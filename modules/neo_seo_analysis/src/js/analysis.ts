/**
 * Neo | SEO Analysis — content analysis for the SEO panel.
 *
 * Two surfaces over one Analyzer:
 * - the edit form (a Drupal.neoSeoPanel extension): the SEO checks under the
 *   focus keyphrase and cornerstone controls in the field's SEO section, and
 *   the readability checks in its Readability section — or, for a page built
 *   in its own Alchemist layout, the two scores and a link to the editor;
 * - Alchemist: the top bar badge (a Drupal.neoSeoPanel.extendBadge()
 *   extension) — two score pills, kept current from editor load, whose
 *   dropdown holds the score help, the keyphrase and cornerstone controls and
 *   the assessments. The Alchemist panel itself carries no analysis.
 *
 * Either way the scores are written back through the host quietly: a score
 * never marks the page changed and never blocks a save or a publish.
 *
 * The engine runs in a Web Worker so typing never waits on it. The worker is
 * inlined (`?worker&inline`) rather than emitted beside the bundle: neo_build
 * serves chunks as classic scripts, where the `new URL(…, import.meta.url)`
 * form is a syntax error, and the emitted file would land outside the
 * manifest. Inline, the worker starts from a Blob URL with nothing to resolve.
 */

import type { AnalysisInput, AnalysisResult, Assessment, Band, OutlineHeading } from './engine/types.ts';
import AnalysisWorker from './analysis.worker?worker&inline';

(function (Drupal, drupalSettings): void {

  interface AnalysisConfig {
    keyphrasesUrl?: string;
    /** Finds the media item behind an image (ImagesController). */
    imagesUrl?: string;
    /**
     * Set for a page built in its own Alchemist layout: the form shows the
     * two scores and the way to the editor, not the checks.
     */
    summary?: { editorUrl: string | null };
  }

  /** Keyphrase and cornerstone change nothing on the page: re-run, no render. */
  const RERUN_WAIT = 300;

  function h<K extends keyof HTMLElementTagNameMap>(
    tag: K,
    attributes: Record<string, string> = {},
    children: Array<Node | string | null> = [],
  ): HTMLElementTagNameMap[K] {
    const element = document.createElement(tag);
    Object.entries(attributes).forEach(([name, value]) => element.setAttribute(name, value));
    children.forEach((child) => child !== null && element.append(child));
    return element;
  }

  function bandLabel(band: Band): string {
    return {
      good: Drupal.t('Good'),
      ok: Drupal.t('Could be better'),
      problem: Drupal.t('Problem'),
    }[band];
  }

  /** A score's traffic light: green 70+, orange 40–69, red below. */
  function scoreBand(score: number): Band {
    return score >= 70 ? 'good' : score >= 40 ? 'ok' : 'problem';
  }

  /** What the two scores mean: the same words in both surfaces. */
  const SCORE_HELP = {
    seo: () => Drupal.t('How well the page is set up to be found for its focus keyphrase. Green 70+, orange 40–69, red below. It is advice: it never stops you saving.'),
    readability: () => Drupal.t('How easy the text is to read. Green 70+, orange 40–69, red below. It is advice: it never stops you saving.'),
  };

  /**
   * A remembered choice (an open or closed list), kept in this browser only.
   * Storage can be unavailable, and then the default simply applies.
   */
  const remembered = {
    get(key: string): boolean | null {
      try {
        const value = window.localStorage.getItem(`neoSeoAnalysis.${key}`);
        return value === null ? null : value === '1';
      }
      catch {
        return null;
      }
    },
    set(key: string, value: boolean): void {
      try {
        window.localStorage.setItem(`neoSeoAnalysis.${key}`, value ? '1' : '0');
      }
      catch {
        // Not remembered; nothing else depends on it.
      }
    },
  };

  /**
   * The site's help badge (neo_base's `form-label-help`, the "?" every
   * described field carries), its explanation shown by neo_tooltip on hover
   * and focus.
   */
  function helpBadge(label: string, text: string): HTMLElement {
    return h('button', {
      type: 'button',
      class: 'form-label-help use-neo-tooltip neo-seo-help-badge',
      'aria-label': label,
      'data-tippy-content': text,
    }, [h('span', { 'aria-hidden': 'true' }, ['?'])]);
  }

  /** Binds neo_tooltip to the badges in a subtree, or releases them. */
  const tooltips = {
    attach(element: HTMLElement): void {
      Drupal.behaviors.neoTooltip?.attach(element, drupalSettings);
    },
    detach(element: HTMLElement): void {
      Drupal.behaviors.neoTooltip?.detach?.(element, drupalSettings, 'unload');
    },
  };

  /**
   * One worker per page, shared by every analyzer; answers go to whoever asked.
   */
  let worker: Worker | null | undefined;
  let nextId = 0;
  const pending = new Map<number, (answer: { result?: AnalysisResult; error?: string }) => void>();

  function engine(): Worker | null {
    if (worker === undefined) {
      try {
        worker = new AnalysisWorker({ name: 'neo-seo-analysis' });
        worker.addEventListener('message', (event: MessageEvent<{ id: number; result?: AnalysisResult; error?: string }>) => {
          pending.get(event.data.id)?.(event.data);
          pending.delete(event.data.id);
        });
        worker.addEventListener('error', (event) => {
          console.error(event.message);
          pending.forEach((resolve) => resolve({ error: event.message }));
          pending.clear();
        });
      }
      catch (e) {
        console.error(e);
        worker = null;
      }
    }
    return worker;
  }

  function analyze(input: AnalysisInput): Promise<AnalysisResult> {
    const target = engine();
    if (!target) {
      return Promise.reject(new Error('No worker.'));
    }
    nextId += 1;
    const id = nextId;
    return new Promise((resolve, reject) => {
      pending.set(id, (answer) => (answer.result ? resolve(answer.result) : reject(new Error(answer.error))));
      target.postMessage({ id, input });
    });
  }

  /**
   * Analyses the latest render for a host, newest run wins, and writes the
   * scores back quietly.
   */
  class Analyzer {

    protected readonly host: NeoSeoPanelHost;
    protected readonly config: AnalysisConfig;
    protected readonly latest: () => NeoSeoDraftRender | null;
    protected readonly resultListeners: Array<(result: AnalysisResult, values: NeoSeoOverrides) => void> = [];
    protected readonly busyListeners: Array<(busy: boolean, failed?: boolean) => void> = [];
    protected keyphrases: Promise<string[] | undefined> | null = null;
    protected run = 0;
    protected timer: number | undefined;

    constructor(host: NeoSeoPanelHost, config: NeoSeoPanelConfig, latest: () => NeoSeoDraftRender | null) {
      this.host = host;
      this.config = (config.analysis ?? {}) as AnalysisConfig;
      this.latest = latest;
      host.onChange((change) => {
        if (change.key === 'keyphrase' || change.key === 'cornerstone') {
          window.clearTimeout(this.timer);
          this.timer = window.setTimeout(() => this.analyze(), RERUN_WAIT);
        }
      });
    }

    onResult(callback: (result: AnalysisResult, values: NeoSeoOverrides) => void): void {
      this.resultListeners.push(callback);
    }

    onBusy(callback: (busy: boolean, failed?: boolean) => void): void {
      this.busyListeners.push(callback);
    }

    /** Other pages' keyphrases, fetched once; without them that check skips. */
    protected usedKeyphrases(): Promise<string[] | undefined> {
      if (!this.keyphrases) {
        const url = this.config.keyphrasesUrl;
        this.keyphrases = url
          ? fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then((response) => (response.ok ? response.json() : null))
            .then((body) => (Array.isArray(body?.keyphrases) ? body.keyphrases as string[] : undefined))
            .catch(() => undefined)
          : Promise.resolve(undefined);
      }
      return this.keyphrases;
    }

    async analyze(): Promise<void> {
      const render = this.latest();
      if (!render) {
        return;
      }
      this.run += 1;
      const run = this.run;
      this.busyListeners.forEach((listener) => listener(true));
      const values = this.host.read();
      let siteHost: string | undefined;
      try {
        siteHost = new URL(render.url, window.location.href).host;
      }
      catch {
        siteHost = undefined;
      }
      const input: AnalysisInput = {
        html: render.html,
        title: render.head.title,
        description: render.head.description,
        url: render.url,
        keyphrase: values.keyphrase,
        cornerstone: values.cornerstone,
        language: render.language,
        siteHost,
      };
      const used = await this.usedKeyphrases();
      if (used) {
        input.usedKeyphrases = used;
      }
      try {
        const result = await analyze(input);
        if (run !== this.run) {
          return;
        }
        this.resultListeners.forEach((listener) => listener(result, values));
        this.busyListeners.forEach((listener) => listener(false));
        this.host.write({
          seo_score: result.seo.score,
          readability_score: result.readability.available ? result.readability.score : null,
        }, { quiet: true });
      }
      catch (e) {
        console.error(e);
        if (run === this.run) {
          this.busyListeners.forEach((listener) => listener(false, true));
        }
      }
    }

  }

  function svg(tag: string, attributes: Record<string, string>): SVGElement {
    const element = document.createElementNS('http://www.w3.org/2000/svg', tag);
    Object.entries(attributes).forEach(([name, value]) => element.setAttribute(name, value));
    return element;
  }

  /**
   * A score as a ring: the arc is the score out of 100 in its band's colour,
   * the number inside (the large ring) or beside it (the pills). No score yet,
   * or none for the page, is an empty ring. A label makes it an image to a
   * screen reader; without one it is decoration beside text that says it.
   */
  function ring(score: number | null, size: 'lg' | 'sm', label = ''): HTMLElement {
    const graphic = svg('svg', { class: 'neo-seo-ring__graphic', viewBox: '0 0 36 36', 'aria-hidden': 'true', focusable: 'false' });
    graphic.append(
      svg('circle', { class: 'neo-seo-ring__track', cx: '18', cy: '18', r: '15', pathLength: '100' }),
      svg('circle', {
        class: 'neo-seo-ring__arc',
        cx: '18',
        cy: '18',
        r: '15',
        pathLength: '100',
        'stroke-dasharray': `${Math.max(0, Math.min(100, score ?? 0))} 100`,
      }),
    );
    const attributes: Record<string, string> = {
      class: `neo-seo-ring neo-seo-ring--${size}`,
      'data-band': score === null ? 'none' : scoreBand(score),
    };
    if (label) {
      attributes.role = 'img';
      attributes['aria-label'] = label;
    }
    else {
      attributes['aria-hidden'] = 'true';
    }
    return h('span', attributes, [
      graphic,
      size === 'lg' ? h('span', { class: 'neo-seo-ring__value', 'aria-hidden': 'true' }, [score === null ? '–' : String(score)]) : null,
    ]);
  }

  /** A score's ring and value, one line: the summaries and the Save summary. */
  function scoreLine(className: string, label: string, value: number | null, note = ''): HTMLElement {
    const band = value === null ? 'none' : scoreBand(value);
    return h('span', { class: className, 'data-band': band }, [
      ring(value, 'sm'),
      h('span', { class: `${className}-label` }, [label]),
      h('strong', {}, [value === null ? '–' : String(value)]),
      value === null ? null : h('span', { class: 'visually-hidden' }, [` / 100, ${bandLabel(band as Band)}`]),
      note ? h('span', { class: `${className}-note` }, [note]) : null,
    ]);
  }

  /**
   * A check's severity as an icon: a red disc with "!" for a problem, an
   * amber triangle with "!" for an improvement, a green disc with a tick for
   * a pass. Decoration: the band is also said in text beside it.
   */
  function severityIcon(band: Band): SVGElement {
    const graphic = svg('svg', {
      class: 'neo-seo-icon',
      'data-band': band,
      viewBox: '0 0 16 16',
      'aria-hidden': 'true',
      focusable: 'false',
    });
    if (band === 'good') {
      graphic.append(
        svg('circle', { class: 'neo-seo-icon__shape', cx: '8', cy: '8', r: '8' }),
        svg('path', { class: 'neo-seo-icon__tick', d: 'M4.6 8.3 7 10.6l4.4-5' }),
      );
      return graphic;
    }
    graphic.append(band === 'problem'
      ? svg('circle', { class: 'neo-seo-icon__shape', cx: '8', cy: '8', r: '8' })
      : svg('path', { class: 'neo-seo-icon__shape neo-seo-icon__shape--triangle', d: 'M8 1.6 15 14.2H1Z' }));
    const top = band === 'problem' ? 3.6 : 5.6;
    const length = band === 'problem' ? 5.4 : 4.4;
    graphic.append(
      svg('rect', { class: 'neo-seo-icon__mark', x: '7.1', y: String(top), width: '1.8', height: String(length), rx: '0.9' }),
      svg('circle', { class: 'neo-seo-icon__mark', cx: '8', cy: String(top + length + 1.9), r: '1' }),
    );
    return graphic;
  }

  /** An eye, for "show in preview". */
  function eyeIcon(): SVGElement {
    const graphic = svg('svg', { class: 'neo-seo-eye', viewBox: '0 0 16 16', 'aria-hidden': 'true', focusable: 'false' });
    graphic.append(
      svg('path', { d: 'M1 8s2.6-4.8 7-4.8S15 8 15 8s-2.6 4.8-7 4.8S1 8 1 8Z' }),
      svg('circle', { cx: '8', cy: '8', r: '2.2' }),
    );
    return graphic;
  }

  type ImageHighlight = Extract<NeoSeoHighlight, { kind: 'image' }>;

  /** The media item behind an image, and its edit form when it may be edited. */
  interface ImageMedia {
    media: number;
    edit: string | null;
  }

  /**
   * An image's file, however many times or sizes the page shows it: its path
   * with an image style's directories and added format taken off. The same
   * rule as the engine's imageFile(), which this bundle cannot import.
   */
  function imageFile(src: string): string {
    const path = src.split(/[?#]/)[0]!.replace(/^[a-z]+:\/\/[^/]+/i, '');
    return path
      .replace(/\/styles\/[^/]+\/[a-z0-9]+\//i, '/')
      .replace(/^(.+\.[a-z0-9]{2,5})\.(avif|webp)$/i, '$1');
  }

  /** An image's file name, as uploaded. */
  function imageName(src: string): string {
    const base = imageFile(src).split('/').pop() ?? '';
    try {
      return decodeURIComponent(base);
    }
    catch {
      return base;
    }
  }

  /**
   * A media item's edit form, in a modal over the page. Saving stays in the
   * modal (MediaFormHooks) and ends with neoSeoMediaSaved.
   */
  function openMedia(url: string): void {
    Drupal.ajax({
      url,
      dialogType: 'modal',
      dialog: { width: '80%' },
      progress: { type: 'fullscreen' },
    } as unknown as Parameters<typeof Drupal.ajax>[0]).execute();
  }

  /** A media item saved from the panel: every surface checks again. */
  const MEDIA_SAVED = 'neo-seo:media-saved';
  if (Drupal.AjaxCommands) {
    Drupal.AjaxCommands.prototype.neoSeoMediaSaved = function (_ajax: unknown, response: { media: number }) {
      document.dispatchEvent(new CustomEvent(MEDIA_SAVED, { detail: { media: response.media } }));
    } as unknown as drupal.Core.IAjaxCommand;
  }

  type Side = 'seo' | 'readability';

  /** What the SEO score was measured against, under its ring. */
  function seoStatus(values: NeoSeoOverrides, score: number): string {
    if (values.cornerstone) {
      return Drupal.t('Cornerstone content: scored against stricter thresholds.');
    }
    if (values.keyphrase.trim() === '') {
      return Drupal.t('No focus keyphrase: set one to run every check.');
    }
    return bandLabel(scoreBand(score));
  }

  /**
   * A score's header: the ring, the score's name and what it means, a status
   * line, and for SEO the keyphrase and cornerstone controls it is measured
   * against. The status line is the surface's one live region: what was
   * measured, or why there is nothing to show.
   */
  class Scorecard {

    readonly element: HTMLElement;
    protected readonly label: string;
    protected readonly ringSlot: HTMLElement;
    protected readonly status: HTMLElement;

    constructor(label: string, help: string, fields: HTMLElement | null = null) {
      this.label = label;
      this.ringSlot = h('span', { class: 'neo-seo-analysis__ring' }, [ring(null, 'lg')]);
      this.status = h('p', { class: 'neo-seo-analysis__status', role: 'status', 'aria-live': 'polite' }, [
        Drupal.t('Analysing…'),
      ]);
      this.element = h('div', { class: 'neo-seo-analysis__scorecard', 'data-band': 'none' }, [
        this.ringSlot,
        h('div', { class: 'neo-seo-analysis__scorecard-body' }, [
          h('h4', { class: 'neo-seo-analysis__title' }, [
            label,
            helpBadge(Drupal.t('About the @title score', { '@title': label }), help),
          ]),
          this.status,
          fields,
        ]),
      ]);
    }

    /** A new score, or none (null), and what it was measured against. */
    show(score: number | null, status: string): void {
      const band = score === null ? 'none' : scoreBand(score);
      this.element.dataset.band = band;
      this.element.classList.remove('is-failed');
      this.ringSlot.replaceChildren(ring(score, 'lg', score === null
        ? Drupal.t('@label score: none', { '@label': this.label })
        : Drupal.t('@label score: @score out of 100, @band', {
          '@label': this.label,
          '@score': String(score),
          '@band': bandLabel(band as Band),
        })));
      this.status.textContent = status;
    }

    /** Re-analysing: the last score stays, the ring turning around it. */
    busy(busy: boolean): void {
      this.element.classList.toggle('is-busy', busy);
      this.element.setAttribute('aria-busy', busy ? 'true' : 'false');
    }

    /** Analysis could not run: the ring empties and the line says why. */
    fail(text: string): void {
      this.busy(false);
      this.element.classList.add('is-failed');
      this.status.textContent = text;
    }

    say(text: string): void {
      this.status.textContent = text;
    }

  }

  /** An assessment, and whether it counts toward its score. */
  interface Check {
    assessment: Assessment;
    scored: boolean;
  }

  /**
   * The SEO and readability checks: problems, then improvements, each under
   * a count, then the passed ones folded into one row; for SEO, the heading
   * outline last. The structure checks (headings, alt text) join the SEO
   * list, tagged as not scored. A check that can be fixed is a row-wide
   * button to its fix; Show in preview is an eye at the row's end.
   */
  class Results {

    protected readonly host: NeoSeoPanelHost;
    protected readonly onMissing: (side: Side) => void;
    protected readonly imagesUrl: string | undefined;
    /** Each image URL's media item, once looked up. */
    protected readonly media = new Map<string, ImageMedia | null>();
    /** Called after a Fix went somewhere. */
    onFixed: (result: NeoSeoFixResult) => void = () => {};
    readonly seo: HTMLElement;
    readonly readability: HTMLElement;
    protected highlighted: HTMLButtonElement | null = null;

    constructor(host: NeoSeoPanelHost, onMissing: (side: Side) => void, imagesUrl?: string) {
      this.host = host;
      this.onMissing = onMissing;
      this.imagesUrl = imagesUrl;
      this.seo = h('div', { class: 'neo-seo-analysis__checks' });
      this.readability = h('div', { class: 'neo-seo-analysis__checks' });
    }

    draw(result: AnalysisResult): void {
      this.clearHighlight();
      const { outline, headings, images } = result.structure;
      this.seo.replaceChildren(...this.checks('seo', [
        ...result.seo.assessments.map((assessment) => ({ assessment, scored: true })),
        ...headings.map((assessment) => ({ assessment, scored: false })),
        ...images.map((assessment) => ({ assessment, scored: false })),
      ]), this.outline(outline, headings));
      this.readability.replaceChildren(...(result.readability.available
        ? this.checks('readability', result.readability.assessments.map((assessment) => ({ assessment, scored: true })))
        : [h('p', { class: 'neo-seo-analysis__note' }, [
          result.readability.message ?? Drupal.t('Readability is analysed for English only.'),
        ])]));
    }

    /**
     * Problems and improvements, each under its count, worst first; the
     * passed checks folded into one "N passed" row whose open state is
     * remembered, for every list at once.
     */
    protected checks(side: Side, checks: Check[]): Node[] {
      const nodes: Node[] = [];
      ([['problem', Drupal.t('Problems')], ['ok', Drupal.t('Improvements')]] as Array<[Band, string]>).forEach(([band, title]) => {
        const matching = checks.filter((check) => check.assessment.band === band);
        if (!matching.length) {
          return;
        }
        const list = h('ul', { class: 'neo-seo-analysis__list' });
        matching.forEach((check) => list.append(this.item(side, check)));
        nodes.push(h('section', { class: 'neo-seo-analysis__band', 'data-band': band, 'aria-label': title }, [
          h('h5', { class: 'neo-seo-analysis__band-title' }, [
            severityIcon(band),
            title,
            h('span', { class: 'neo-seo-analysis__count' }, [String(matching.length)]),
          ]),
          list,
        ]));
      });
      if (!nodes.length && checks.length) {
        nodes.push(h('p', { class: 'neo-seo-analysis__clear' }, [Drupal.t('Nothing to fix.')]));
      }
      const passed = checks.filter((check) => check.assessment.band === 'good');
      if (passed.length) {
        const list = h('ul', { class: 'neo-seo-analysis__list' });
        passed.forEach((check) => list.append(this.item(side, check)));
        const details = h('details', { class: 'neo-seo-analysis__passed' }, [
          h('summary', { class: 'neo-seo-analysis__row', 'data-band': 'good' }, [
            severityIcon('good'),
            Drupal.formatPlural(passed.length, '1 passed', '@count passed'),
          ]),
          list,
        ]);
        details.open = remembered.get('passedOpen') ?? false;
        details.addEventListener('toggle', () => remembered.set('passedOpen', details.open));
        nodes.push(details);
      }
      return nodes;
    }

    /**
     * The headings as an indented tree, folded under a count, the list's last
     * row. It opens on its own when the outline has a problem; a choice made
     * remembers itself, separately for an outline with problems and one
     * without.
     */
    protected outline(outline: OutlineHeading[], headings: Assessment[]): HTMLElement {
      const name = h('span', { class: 'neo-seo-analysis__row-name' }, [Drupal.t('Heading outline')]);
      if (!outline.length) {
        return h('p', { class: 'neo-seo-analysis__row neo-seo-analysis__row--static' }, [
          name,
          h('span', { class: 'neo-seo-analysis__row-meta' }, [Drupal.t('No headings')]),
        ]);
      }
      const problems = headings.some((assessment) => assessment.band !== 'good') || outline.some((heading) => heading.issue);
      const tree = h('ol', { class: 'neo-seo-analysis__outline', 'aria-label': Drupal.t('Headings on the page, in order') });
      outline.forEach((heading) => tree.append(this.outlineItem(heading)));
      const issues = outline.filter((heading) => heading.issue).length;
      const key = problems ? 'outlineOpen.problems' : 'outlineOpen.clean';
      const details = h('details', { class: 'neo-seo-analysis__tree' }, [
        h('summary', { class: 'neo-seo-analysis__row' }, [
          name,
          h('span', { class: 'neo-seo-analysis__row-meta' }, [Drupal.formatPlural(outline.length, '1 heading', '@count headings')]),
          issues
            ? h('span', { class: 'neo-seo-analysis__issue' }, [Drupal.formatPlural(issues, '1 issue', '@count issues')])
            : null,
        ]),
        tree,
      ]);
      details.open = remembered.get(key) ?? problems;
      details.addEventListener('toggle', () => remembered.set(key, details.open));
      return details;
    }

    /**
     * One heading of the outline. Where the host can take the editor to it
     * (Alchemist), it is a button that does.
     */
    protected outlineItem(heading: OutlineHeading): HTMLElement {
      const label = [
        h('span', { class: 'neo-seo-analysis__level' }, [`H${heading.level}`]),
        h('span', { class: 'neo-seo-analysis__outline-text' }, [heading.text]),
        heading.issue
          ? h('span', { class: 'neo-seo-analysis__issue' }, [
            heading.issue === 'extraH1' ? Drupal.t('second H1') : Drupal.t('skips a level'),
          ])
          : null,
      ];
      const item = h('li', {
        class: 'neo-seo-analysis__outline-item',
        'data-level': String(heading.level),
        'data-issue': heading.issue ?? '',
      });
      const target: NeoSeoHighlight[] = [{ kind: 'heading', level: heading.level, text: heading.text }];
      if (this.host.fix && this.host.canFix?.('outline', target)) {
        const button = h('button', {
          type: 'button',
          class: 'neo-seo-analysis__outline-link',
          'aria-label': Drupal.t('Go to heading: @text', { '@text': heading.text }),
        }, label);
        button.addEventListener('click', () => {
          const result = this.host.fix?.('outline', target) ?? null;
          if (result) {
            this.onFixed(result);
          }
          else {
            this.onMissing('seo');
          }
        });
        item.append(button);
      }
      else {
        label.forEach((part) => part && item.append(part));
      }
      return item;
    }

    /**
     * One check: its dot, message and "Not scored" tag; at its end the eye
     * that shows it in the preview, and, where it can be fixed, "Fix ›" —
     * a button whose hit area covers the row, so the whole row goes to the
     * fix. The eye sits above that area and only toggles the marks.
     */
    protected item(side: Side, check: Check): HTMLElement {
      if (check.assessment.id === 'imageAltText' && check.assessment.band !== 'good') {
        return this.images(side, check);
      }
      const { assessment, scored } = check;
      const highlights = assessment.highlights ?? [];
      const controls: HTMLElement[] = [];
      if (highlights.length && this.host.highlight) {
        const show = h('button', {
          type: 'button',
          class: 'neo-seo-analysis__show',
          'aria-pressed': 'false',
          'aria-label': Drupal.t('Show in the preview: @message', { '@message': assessment.message }),
          title: Drupal.t('Show in preview'),
        }, [eyeIcon()]);
        show.addEventListener('click', () => this.toggleHighlight(side, show, highlights));
        controls.push(show);
      }
      const fixable = assessment.band !== 'good' && !!this.host.fix && !!this.host.canFix?.(assessment.id, highlights);
      if (fixable) {
        const fix = h('button', {
          type: 'button',
          class: 'neo-seo-analysis__fix',
          'aria-label': Drupal.t('Go to the field that fixes this: @message', { '@message': assessment.message }),
        }, [Drupal.t('Fix'), h('span', { class: 'neo-seo-analysis__chevron', 'aria-hidden': 'true' })]);
        fix.addEventListener('click', () => {
          const result = this.host.fix?.(assessment.id, highlights) ?? null;
          if (result) {
            this.onFixed(result);
          }
          else {
            this.onMissing(side);
          }
        });
        controls.push(fix);
      }
      return h('li', {
        class: `neo-seo-analysis__item${fixable ? ' is-fixable' : ''}`,
        'data-band': assessment.band,
      }, [
        h('span', { class: 'neo-seo-analysis__dot', 'aria-hidden': 'true' }),
        h('span', { class: 'neo-seo-analysis__body' }, [
          h('span', { class: 'visually-hidden' }, [`${bandLabel(assessment.band)}: `]),
          h('span', { class: 'neo-seo-analysis__message' }, [assessment.message]),
          scored ? null : h('span', { class: 'neo-seo-analysis__tag' }, [Drupal.t('Not scored')]),
        ]),
        controls.length ? h('span', { class: 'neo-seo-analysis__controls' }, controls) : null,
      ]);
    }

    /**
     * The alt text check: a summary row, its eye marking every flagged image,
     * over one row per image file — thumbnail, name, why, how often — each
     * with its own eye and Fix. Fix opens the media item, where alt text is
     * kept; without one (or without access), the component showing it.
     */
    protected images(side: Side, { assessment, scored }: Check): HTMLElement {
      const highlights = (assessment.highlights ?? []).filter((target): target is ImageHighlight => target.kind === 'image');
      const files = new Map<string, ImageHighlight[]>();
      highlights.forEach((target) => {
        const key = imageFile(target.src);
        files.set(key, [...(files.get(key) ?? []), target]);
      });
      const lookup = this.lookup([...files.values()].map((uses) => uses[0]!.src));
      const list = h('ul', { class: 'neo-seo-analysis__images', 'aria-label': Drupal.t('Images to describe') });
      files.forEach((uses) => list.append(this.image(side, uses, lookup)));
      const details = h('details', { class: 'neo-seo-analysis__image-list' }, [
        h('summary', { class: 'neo-seo-analysis__image-summary', 'data-band': assessment.band }, [
          h('span', { class: 'neo-seo-analysis__dot', 'aria-hidden': 'true' }),
          h('span', { class: 'neo-seo-analysis__body' }, [
            h('span', { class: 'visually-hidden' }, [`${bandLabel(assessment.band)}: `]),
            h('span', { class: 'neo-seo-analysis__message' }, [assessment.message]),
            scored ? null : h('span', { class: 'neo-seo-analysis__tag' }, [Drupal.t('Not scored')]),
          ]),
        ]),
        list,
        h('p', { class: 'neo-seo-analysis__image-note' }, [
          Drupal.t('Alt text is saved on the media item, so it changes everywhere the image is used.'),
        ]),
      ]);
      details.open = remembered.get('imagesOpen') ?? true;
      details.addEventListener('toggle', () => remembered.set('imagesOpen', details.open));
      const all = this.showButton(side, highlights, assessment.message);
      return h('li', { class: 'neo-seo-analysis__item neo-seo-analysis__item--images', 'data-band': assessment.band }, [
        details,
        all ? h('span', { class: 'neo-seo-analysis__controls neo-seo-analysis__controls--summary' }, [all]) : null,
      ]);
    }

    /** One flagged image file, and every place the page shows it. */
    protected image(side: Side, uses: ImageHighlight[], lookup: Promise<void>): HTMLElement {
      const first = uses[0]!;
      const name = imageName(first.src);
      const why = (first.alt ?? '').trim() === '' ? Drupal.t('No alt text') : Drupal.t('Alt is the file name');
      const controls = h('span', { class: 'neo-seo-analysis__controls' });
      const show = this.showButton(side, uses, name);
      if (show) {
        controls.append(show);
      }
      const row = h('li', { class: 'neo-seo-analysis__image' }, [
        h('img', { class: 'neo-seo-analysis__thumb', src: first.src, alt: '', loading: 'lazy', decoding: 'async' }),
        h('span', { class: 'neo-seo-analysis__body' }, [
          h('span', { class: 'neo-seo-analysis__image-name' }, [name]),
          h('span', { class: 'neo-seo-analysis__image-meta' }, [
            uses.length > 1 ? `${why} · ${Drupal.formatPlural(uses.length, 'used once', 'used @count times')}` : why,
          ]),
        ]),
        controls,
      ]);
      lookup.then(() => {
        const found = this.media.get(first.src) ?? null;
        let go: (() => void) | null = null;
        if (found?.edit) {
          const url = found.edit;
          go = () => {
            this.onFixed('component');
            openMedia(url);
          };
        }
        else if (this.host.fix && this.host.canFix?.('imageAltText', uses)) {
          go = () => {
            const result = this.host.fix?.('imageAltText', uses) ?? null;
            if (result) {
              this.onFixed(result);
            }
            else {
              this.onMissing(side);
            }
          };
        }
        if (!go) {
          return;
        }
        const fix = h('button', {
          type: 'button',
          class: 'neo-seo-analysis__fix',
          'aria-label': found?.edit
            ? Drupal.t('Edit the alt text of @name', { '@name': name })
            : Drupal.t('Go to the component showing @name', { '@name': name }),
        }, [Drupal.t('Fix'), h('span', { class: 'neo-seo-analysis__chevron', 'aria-hidden': 'true' })]);
        fix.addEventListener('click', go);
        controls.append(fix);
        row.classList.add('is-fixable');
      });
      return row;
    }

    /** An eye marking targets in the preview, where the host has one. */
    protected showButton(side: Side, targets: NeoSeoHighlight[], what: string): HTMLButtonElement | null {
      if (!targets.length || !this.host.highlight) {
        return null;
      }
      const show = h('button', {
        type: 'button',
        class: 'neo-seo-analysis__show',
        'aria-pressed': 'false',
        'aria-label': Drupal.t('Show in the preview: @what', { '@what': what }),
        title: Drupal.t('Show in preview'),
      }, [eyeIcon()]);
      show.addEventListener('click', () => this.toggleHighlight(side, show, targets));
      return show;
    }

    /**
     * Looks up the media items behind image URLs not looked up yet, in one
     * request. Without the endpoint, or when it fails, none are found.
     */
    protected async lookup(srcs: string[]): Promise<void> {
      const wanted = srcs.filter((src) => !this.media.has(src));
      if (!wanted.length || !this.imagesUrl) {
        return;
      }
      const url = new URL(this.imagesUrl, window.location.href);
      wanted.forEach((src) => url.searchParams.append('src[]', src));
      try {
        const response = await fetch(url.toString(), { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        const body = response.ok ? await response.json() as { images?: Record<string, ImageMedia | null> } : {};
        wanted.forEach((src) => this.media.set(src, body.images?.[src] ?? null));
      }
      catch {
        // Not found: the rows fall back to the component, or offer no Fix.
      }
    }

    /** Forgets what was looked up: a media item was saved. */
    forget(): void {
      this.media.clear();
    }

    protected toggleHighlight(side: Side, button: HTMLButtonElement, highlights: NeoSeoHighlight[]): void {
      const on = this.highlighted !== button;
      this.clearHighlight();
      if (on && this.host.highlight?.(highlights)) {
        this.highlighted = button;
        button.setAttribute('aria-pressed', 'true');
      }
      else if (on) {
        this.onMissing(side);
      }
    }

    clearHighlight(): void {
      if (this.highlighted) {
        this.highlighted.setAttribute('aria-pressed', 'false');
        this.highlighted = null;
        this.host.highlight?.(null);
      }
    }

  }

  /**
   * The edit form's analysis: in the field's SEO and Readability sections,
   * each a score's header and its checks, or, for a page built in its own
   * Alchemist layout, the two scores and the way to the editor (summary()).
   */
  function mountSection(context: NeoSeoPanelContext): void {
    const config = (context.config.analysis ?? {}) as AnalysisConfig;
    const seo = context.section('seo', Drupal.t('SEO'));
    seo.classList.add('neo-seo-analysis');
    const analyzer = new Analyzer(context.host, context.config, () => context.latest());
    const missing = Drupal.t('The preview does not show that text.');
    const failed = Drupal.t('Analysis is not available in this browser.');

    if (config.summary) {
      // The inputs stay where the form has them: they are edited here, and
      // scored in the editor.
      if (!seo.dataset.neoSeoSection) {
        (['keyphrase', 'cornerstone'] as NeoSeoOverrideKey[]).forEach((key) => {
          const control = context.host.control(key);
          if (control) {
            seo.append(control);
          }
        });
      }
      const status = h('p', { class: 'neo-seo-analysis__status', role: 'status', 'aria-live': 'polite' }, [
        Drupal.t('Analysis runs when the preview is ready.'),
      ]);
      const scores = h('div', { class: 'neo-seo-analysis__summary' });
      seo.append(status, scores, editorLink(seo, config.summary.editorUrl));
      analyzer.onBusy((busy, error) => {
        status.textContent = busy ? Drupal.t('Analysing…') : (error ? failed : status.textContent);
      });
      analyzer.onResult((result, values) => {
        scores.replaceChildren(
          scoreLine('neo-seo-analysis__summary-score', Drupal.t('SEO'), result.seo.score,
            values.keyphrase.trim() === '' ? Drupal.t('no focus keyphrase') : ''),
          scoreLine('neo-seo-analysis__summary-score', Drupal.t('Readability'),
            result.readability.available ? result.readability.score : null),
        );
        status.textContent = Drupal.t('Scores are advice: they never stop a save.');
      });
    }
    else {
      // The inputs analysis reads join the SEO score's header. Moved, not
      // copied: they stay the host's controls and save the way they always
      // did, help badge and all.
      const fields = h('div', { class: 'neo-seo-analysis__fields' });
      (['keyphrase', 'cornerstone'] as NeoSeoOverrideKey[]).forEach((key) => {
        const control = context.host.control(key);
        if (control) {
          fields.append(control);
        }
      });
      const cards: Record<Side, Scorecard> = {
        seo: new Scorecard(Drupal.t('SEO'), SCORE_HELP.seo(), fields),
        readability: new Scorecard(Drupal.t('Readability'), SCORE_HELP.readability()),
      };
      const results = new Results(context.host, (side) => cards[side].say(missing), config.imagesUrl);
      document.addEventListener(MEDIA_SAVED, () => {
        results.forget();
        context.refresh();
      });
      seo.append(cards.seo.element, results.seo);
      const readability = context.section('readability', Drupal.t('Readability'));
      readability.classList.add('neo-seo-analysis');
      readability.append(cards.readability.element, results.readability);
      tooltips.attach(cards.seo.element);
      tooltips.attach(cards.readability.element);
      analyzer.onBusy((busy, error) => {
        (Object.values(cards)).forEach((card) => (error ? card.fail(failed) : card.busy(busy)));
      });
      analyzer.onResult((result, values) => {
        results.draw(result);
        cards.seo.show(result.seo.score, seoStatus(values, result.seo.score));
        cards.readability.show(
          result.readability.available ? result.readability.score : null,
          result.readability.available ? bandLabel(scoreBand(result.readability.score)) : Drupal.t('Not analysed'),
        );
        Object.values(cards).forEach((card) => card.busy(false));
      });
    }
    context.onRender(() => analyzer.analyze());
    if (context.latest()) {
      analyzer.analyze();
    }
  }

  /**
   * The way to the layout editor, opening on its SEO checks: where the
   * checks of a page built there can be acted on. Leaving asks first while
   * the form holds changes not yet saved. Without access, a note instead.
   */
  function editorLink(section: HTMLElement, url: string | null): HTMLElement {
    if (!url) {
      return h('p', { class: 'neo-seo-analysis__note' }, [
        Drupal.t('This page is built in the layout editor, where its checks are.'),
      ]);
    }
    const link = h('a', { href: url, class: 'btn btn-xs btn-outline neo-seo-analysis__editor-link' }, [
      Drupal.t('Open in the layout editor'),
    ]);
    const form = section.closest('form');
    const snapshot = () => (form ? formState(form) : '');
    // After every behavior has attached, so what they set counts as loaded.
    let loaded = '';
    window.setTimeout(() => {
      loaded = snapshot();
    }, 0);
    link.addEventListener('click', (event) => {
      if (form && snapshot() !== loaded && !window.confirm(Drupal.t('Leave this page? Changes you have not saved will be lost.'))) {
        event.preventDefault();
      }
    });
    return h('div', { class: 'neo-seo-analysis__editor' }, [
      h('p', { class: 'neo-seo-analysis__note' }, [
        Drupal.t('This page is built in the layout editor. Its checks, and the way to each fix, are there.'),
      ]),
      link,
    ]);
  }

  /**
   * What a form would submit, less what changes without the editor changing
   * anything: the form's build id and token, and the scores analysis writes.
   */
  function formState(form: HTMLFormElement): string {
    const ignored = /^(form_build_id|form_token|form_id)$|\[(seo_score|readability_score)\]$/;
    const pairs: string[] = [];
    new FormData(form).forEach((value, name) => {
      if (!ignored.test(name) && typeof value === 'string') {
        pairs.push(`${name}=${value}`);
      }
    });
    return pairs.join('&');
  }

  /**
   * The Alchemist top bar badge: two score pills and their dropdown.
   */
  function mountBadge(badge: HTMLElement, dropdown: NeoAlchemistDropdown, editor: NeoSeoEditor): void {
    const host = editor.host;
    const pills = {
      seo: badge.querySelector<HTMLElement>('[data-neo-seo-score="seo"]'),
      readability: badge.querySelector<HTMLElement>('[data-neo-seo-score="readability"]'),
    };

    // The dropdown: one view per pill, each a score's header and its checks,
    // one column. The SEO header holds what the page is scored against.
    const body = dropdown.element;
    body.classList.add('neo-seo-analysis', 'neo-seo-analysis--dropdown');
    const fields = h('div', { class: 'neo-seo-analysis__fields' });
    // The panel's own keyphrase and cornerstone controls move here: they
    // still draft through the panel's draft key, like every SEO field.
    (['keyphrase', 'cornerstone'] as NeoSeoOverrideKey[]).forEach((key) => {
      const control = host.control(key);
      if (control) {
        fields.append(control);
      }
    });
    editor.addRoot(fields);
    const cards: Record<Side, Scorecard> = {
      seo: new Scorecard(Drupal.t('SEO'), SCORE_HELP.seo(), fields),
      readability: new Scorecard(Drupal.t('Readability'), SCORE_HELP.readability()),
    };
    const config = (editor.config.analysis ?? {}) as AnalysisConfig;
    const results = new Results(host, (side) => cards[side].say(Drupal.t('The preview does not show that text.')), config.imagesUrl);
    // A media item saved from the alt text check: render and check again.
    document.addEventListener(MEDIA_SAVED, () => {
      results.forget();
      editor.refresh();
    });
    const sides: Record<Side, { label: string; content: Node[] }> = {
      seo: { label: Drupal.t('SEO'), content: [cards.seo.element, results.seo] },
      readability: { label: Drupal.t('Readability'), content: [cards.readability.element, results.readability] },
    };
    let current: Side = 'seo';
    // No tabs: the pills are the switch. The open pill's side is shown; the
    // other pill swaps it; the open pill again closes (neo_alchemist).
    const panes = {} as Record<Side, HTMLElement>;
    (Object.keys(sides) as Side[]).forEach((side) => {
      const pane = h('div', {
        id: `neo-seo-analysis-pane-${side}`,
        class: 'neo-seo-analysis__pane',
        role: 'group',
        'aria-label': sides[side].label,
      }, sides[side].content);
      pane.hidden = true;
      panes[side] = pane;
    });
    const select = (side: Side) => {
      (Object.keys(panes) as Side[]).forEach((key) => {
        const on = key === side;
        panes[key].hidden = !on;
        pills[key]?.classList.toggle('is-active', on && dropdown.isOpen());
        pills[key]?.setAttribute('aria-pressed', on && dropdown.isOpen() ? 'true' : 'false');
      });
      if (side !== current) {
        results.clearHighlight();
      }
      current = side;
      dropdown.element.setAttribute('aria-label', sides[side].label);
      // Optional: a neo_alchemist without setSize() keeps its one width.
      dropdown.setSize?.('lg');
    };
    select('seo');
    // The SEO fields themselves — title, description, share image — are in the
    // Search & social panel: one click there from the checks about them.
    const children: Node[] = [panes.seo, panes.readability];
    if (host.showPanel) {
      const edit = h('button', { type: 'button', class: 'btn btn-xs btn-outline neo-seo-analysis__panel-link' }, [
        Drupal.t('Edit search & social'),
      ]);
      edit.addEventListener('click', () => {
        dropdown.close();
        host.showPanel?.();
      });
      children.push(h('div', { class: 'neo-seo-analysis__footer' }, [edit]));
    }
    body.replaceChildren(...children);
    tooltips.attach(cards.seo.element);
    tooltips.attach(cards.readability.element);
    // A Fix that went to the panel or the canvas leaves the dropdown; one that
    // went to the keyphrase field, which lives in it, keeps it open.
    results.onFixed = (result) => {
      if (result !== 'field') {
        dropdown.close();
        return;
      }
      window.setTimeout(() => {
        if (!dropdown.element.contains(document.activeElement)) {
          dropdown.close();
        }
      }, 120);
    };
    dropdown.onToggle((open, trigger) => {
      if (!open) {
        results.clearHighlight();
        (['seo', 'readability'] as Side[]).forEach((key) => {
          pills[key]?.classList.remove('is-active');
          pills[key]?.setAttribute('aria-pressed', 'false');
        });
        return;
      }
      select(trigger?.dataset.neoSeoScore === 'readability' ? 'readability' : 'seo');
    });

    openChecks = (side) => {
      if (!dropdown.isOpen() || current !== side) {
        pills[side]?.click();
      }
    };

    const keyphraseInput = () => fields.querySelector<HTMLInputElement>('[name="neo_seo[0][keyphrase]"]');
    // No keyphrase: the SEO pill opens straight onto the field. Runs after
    // the editor's own toggle, so the field takes the focus.
    badge.addEventListener('click', (event) => {
      const pill = (event.target as Element).closest<HTMLElement>('[data-neo-seo-score="seo"]');
      if (pill?.dataset.missing === 'true' && dropdown.isOpen()) {
        keyphraseInput()?.focus();
      }
    });

    // A pill shows its score as the dropdown does — SEO's without a keyphrase
    // too, the tooltip saying the keyphrase checks are not in it.
    const setPill = (pill: HTMLElement | null, score: number | null, missing = false) => {
      if (!pill) {
        return;
      }
      const value = pill.querySelector<HTMLElement>('.neo-seo-pill__value');
      // A ring (the panel's score row) or a bar (the Alchemist band's meters).
      pill.querySelector<HTMLElement>('.neo-seo-pill__ring')?.replaceChildren(ring(score, 'sm'));
      pill.querySelector<HTMLElement>('.neo-seo-pill__fill')?.style.setProperty('width', `${Math.max(0, Math.min(100, score ?? 0))}%`);
      pill.dataset.missing = missing ? 'true' : 'false';
      const band = score === null ? 'none' : scoreBand(score);
      pill.dataset.band = band;
      if (value) {
        value.hidden = false;
        value.textContent = score === null ? '–' : String(score);
      }
      // A neo_tooltip, like the rest of the header's; the text is kept on the
      // element too, for an instance created after this.
      const text = score === null
        ? (pill.dataset.label ?? '')
        : missing
          ? Drupal.t('@score / 100 without the keyphrase checks: set a focus keyphrase to run them.', { '@score': String(score) })
          : `${bandLabel(band as Band)}: ${score} / 100`;
      pill.dataset.tippyContent = text;
      (pill as HTMLElement & { _tippy?: { setContent(content: string): void } })._tippy?.setContent(text);
    };
    const setUpdating = (updating: boolean) => {
      [pills.seo, pills.readability].forEach((pill) => pill?.classList.toggle('is-updating', updating));
      badge.setAttribute('aria-busy', updating ? 'true' : 'false');
      Object.values(cards).forEach((card) => card.busy(updating));
    };

    const analyzer = new Analyzer(host, editor.config, () => editor.latest());
    analyzer.onBusy((busy, failed) => {
      if (busy) {
        setUpdating(true);
      }
      else if (failed) {
        setUpdating(false);
        Object.values(cards).forEach((card) => card.fail(Drupal.t('Analysis is not available in this browser.')));
      }
    });
    badgeState = { editor, result: null, keyphraseMissing: false };
    analyzer.onResult((result, values) => {
      results.draw(result);
      const missing = values.keyphrase.trim() === '';
      if (badgeState) {
        badgeState.result = result;
        badgeState.keyphraseMissing = missing;
      }
      const readability = result.readability.available ? result.readability.score : null;
      cards.seo.show(result.seo.score, seoStatus(values, result.seo.score));
      cards.readability.show(readability, readability === null ? Drupal.t('Not analysed') : bandLabel(scoreBand(readability)));
      setPill(pills.seo, result.seo.score, missing);
      setPill(pills.readability, readability);
      scoreListeners.forEach((listener) => listener());
      setUpdating(false);
    });
    editor.onState((state) => {
      if (state === 'updating') {
        setUpdating(true);
      }
      else if (state === 'error') {
        setUpdating(false);
        Object.values(cards).forEach((card) => card.fail(Drupal.t('The page could not be rendered for analysis.')));
      }
    });
    editor.onRender(() => analyzer.analyze());
    if (editor.latest()) {
      analyzer.analyze();
    }

    // Sent here by the edit form's "Open in the layout editor": open on the
    // checks asked for, once, and take the request off the address.
    const asked = new URLSearchParams(window.location.search).get('neo_seo');
    if (asked === 'seo' || asked === 'readability') {
      const url = new URL(window.location.href);
      url.searchParams.delete('neo_seo');
      window.history.replaceState(window.history.state, '', url.toString());
      window.setTimeout(() => openChecks?.(asked), 0);
    }
  }

  /** Opens the badge's dropdown on a view; set once the badge is mounted. */
  let openChecks: ((side: Side) => void) | null = null;

  /** Called after the badge shows new scores. */
  const scoreListeners: Array<() => void> = [];

  /**
   * The Search & social panel's scores row: the two scores as the top bar
   * shows them, each opening its checks there. The panel is where the page's
   * search listing is edited, the checks are how well it scores — each points
   * to the other.
   */
  function mountScores(context: NeoSeoPanelContext): void {
    const row = h('div', { class: 'neo-seo-panel-scores', role: 'group', 'aria-label': Drupal.t('Scores') }, [
      h('span', { class: 'neo-seo-panel-scores__label' }, [Drupal.t('Scores')]),
    ]);
    const buttons = (['seo', 'readability'] as const).map((side) => {
      const value = h('span', { class: 'neo-seo-pill__value' }, ['–']);
      const graphic = h('span', { class: 'neo-seo-pill__ring' }, [ring(null, 'sm')]);
      const button = h('button', {
        type: 'button',
        class: 'neo-seo-pill btn btn-xs btn-outline',
        'data-band': 'none',
      }, [
        graphic,
        h('span', { class: 'neo-seo-pill__label' }, [side === 'seo' ? Drupal.t('SEO') : Drupal.t('Read')]),
        value,
      ]);
      button.setAttribute('aria-label', side === 'seo' ? Drupal.t('Show the SEO checks') : Drupal.t('Show the readability checks'));
      button.addEventListener('click', () => openChecks?.(side));
      row.append(button);
      return { side, button, value, graphic };
    });
    const update = () => {
      const state = badgeState;
      if (!state?.result) {
        return;
      }
      buttons.forEach(({ side, button, value, graphic }) => {
        const score = side === 'seo'
          ? state.result!.seo.score
          : (state.result!.readability.available ? state.result!.readability.score : null);
        button.dataset.band = score === null ? 'none' : scoreBand(score);
        graphic.replaceChildren(ring(score, 'sm'));
        value.textContent = score === null ? '–' : String(score);
      });
    };
    scoreListeners.push(update);
    update();
    context.top(row);
  }

  /**
   * What the top bar badge last showed, for the Save confirmation's summary.
   */
  let badgeState: {
    editor: NeoSeoEditor;
    result: AnalysisResult | null;
    keyphraseMissing: boolean;
  } | null = null;

  /**
   * Fills the Save confirmation's SEO summary (neo_seo_analysis' form_alter
   * puts an empty, hidden place for it there) from the analysis the badge
   * already holds: the two scores as the badge shows them, the top three
   * problems, and a warning when the page is set to hide from search — the
   * drafted setting, not only the saved one. Advice only: it adds nothing
   * to submit and is left hidden until the first analysis has run.
   */
  function fillSaveSummary(mount: HTMLElement): void {
    const state = badgeState;
    if (!state?.result) {
      return;
    }
    const { result, editor } = state;
    const scores = h('p', { class: 'neo-seo-save-summary__scores' }, [
      scoreLine('neo-seo-save-summary__score', Drupal.t('SEO'), result.seo.score,
        state.keyphraseMissing ? Drupal.t('no focus keyphrase') : ''),
      scoreLine('neo-seo-save-summary__score', Drupal.t('Readability'),
        result.readability.available ? result.readability.score : null),
    ]);

    const children: Node[] = [h('h3', { class: 'neo-seo-save-summary__title' }, [Drupal.t('SEO check')]), scores];
    const render = editor.latest();
    if (editor.host.read().noindex || /noindex/i.test(render?.head.robots ?? '')) {
      children.push(h('p', { class: 'neo-seo-save-summary__warning', role: 'note' }, [
        Drupal.t('This page is set to hide from search results: search engines will not list it.'),
      ]));
    }
    // Worst first: problems from every check, SEO before structure before
    // readability, the first three.
    const problems = [
      ...result.seo.assessments,
      ...result.structure.headings,
      ...result.structure.images,
      ...result.readability.assessments,
    ].filter((assessment) => assessment.band === 'problem').slice(0, 3);
    if (problems.length) {
      const list = h('ul', { class: 'neo-seo-save-summary__problems' });
      problems.forEach((assessment) => list.append(h('li', {}, [assessment.message])));
      children.push(h('p', { class: 'neo-seo-save-summary__label' }, [Drupal.t('Top problems:')]), list);
    }
    else {
      children.push(h('p', { class: 'neo-seo-save-summary__label' }, [Drupal.t('No problems found.')]));
    }
    // Text only, on purpose: nothing in it can take focus, so the
    // confirmation opens, focuses and submits exactly as it did without it.
    // The full list (with Show and Fix) is one click away in the top bar.
    children.push(h('p', { class: 'neo-seo-save-summary__note' }, [
      Drupal.t('Advice only: saving works the same either way. The SEO and Read buttons in the top bar list every check.'),
    ]));
    mount.replaceChildren(...children);
    mount.hidden = false;
  }

  Drupal.behaviors.neoSeoSaveSummary = {
    attach(context: HTMLElement) {
      once('neo-seo-save-summary', '[data-neo-seo-save-summary]', context).forEach(fillSaveSummary);
    },
  };

  Drupal.neoSeoPanel?.extend({
    id: 'analysis',
    mount(context) {
      // In Alchemist the analysis lives in the top bar badge; the panel shows
      // its scores and opens it.
      if (context.host.kind === 'form') {
        mountSection(context);
      }
      else {
        mountScores(context);
      }
    },
  });

  Drupal.neoSeoPanel?.extendBadge({
    id: 'analysis',
    mount: mountBadge,
  });

})(Drupal, drupalSettings);
