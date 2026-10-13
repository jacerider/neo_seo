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

  const ORDER: Record<Band, number> = { problem: 0, ok: 1, good: 2 };

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

  /**
   * The SEO and readability assessment lists, with a Show button where the
   * host can mark an assessment's highlights in its preview.
   */
  class Results {

    readonly element: HTMLElement;
    protected readonly host: NeoSeoPanelHost;
    protected readonly onMissing: () => void;
    /** Called after a Fix went somewhere. */
    onFixed: (result: NeoSeoFixResult) => void = () => {};
    readonly seo: HTMLElement;
    readonly readability: HTMLElement;
    /** The heading outline and its checks. */
    readonly outline: HTMLElement;
    /** The image alt text check. */
    readonly images: HTMLElement;
    protected highlighted: HTMLButtonElement | null = null;

    constructor(host: NeoSeoPanelHost, onMissing: () => void) {
      this.host = host;
      this.onMissing = onMissing;
      this.seo = h('div', { class: 'neo-seo-analysis__group' });
      this.readability = h('div', { class: 'neo-seo-analysis__group' });
      this.outline = h('div', { class: 'neo-seo-analysis__group' });
      this.images = h('div', { class: 'neo-seo-analysis__group' });
      this.element = h('div', { class: 'neo-seo-analysis__results' }, [this.seo, this.outline, this.images, this.readability]);
    }

    draw(result: AnalysisResult): void {
      this.clearHighlight();
      this.group(this.seo, Drupal.t('SEO'), SCORE_HELP.seo(), result.seo.score, result.seo.assessments, null);
      this.group(
        this.readability,
        Drupal.t('Readability'),
        SCORE_HELP.readability(),
        result.readability.score,
        result.readability.assessments,
        result.readability.available ? null : (result.readability.message ?? Drupal.t('Readability is analysed for English only.')),
      );
      this.structure(result);
    }

    /**
     * Assessments worst first, with the passed ones folded into one "N
     * passed" row: what needs doing is what is read first. The row's open
     * state is remembered, for every list at once.
     */
    protected checks(assessments: Assessment[]): Node[] {
      const sorted = [...assessments].sort((a, b) => ORDER[a.band] - ORDER[b.band]);
      const open = sorted.filter((assessment) => assessment.band !== 'good');
      const passed = sorted.filter((assessment) => assessment.band === 'good');
      const nodes: Node[] = [];
      if (open.length) {
        const list = h('ul', { class: 'neo-seo-analysis__list' });
        open.forEach((assessment) => list.append(this.item(assessment)));
        nodes.push(list);
      }
      if (passed.length) {
        const list = h('ul', { class: 'neo-seo-analysis__list' });
        passed.forEach((assessment) => list.append(this.item(assessment)));
        const details = h('details', { class: 'neo-seo-analysis__passed' }, [
          h('summary', { class: 'neo-seo-analysis__passed-summary', 'data-band': 'good' }, [
            h('span', { class: 'neo-seo-analysis__dot', 'aria-hidden': 'true' }),
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
     * The heading outline (its checks, then the headings as an indented tree,
     * flagged where they break it) and the image alt text check. Not scored.
     */
    protected structure(result: AnalysisResult): void {
      const { outline, headings, images } = result.structure;
      this.outline.replaceChildren(
        h('h4', { class: 'neo-seo-analysis__heading' }, [Drupal.t('Heading outline')]),
        ...this.checks(headings),
        outline.length
          ? this.tree(outline, headings.some((assessment) => assessment.band !== 'good') || outline.some((heading) => heading.issue))
          : h('p', { class: 'neo-seo-analysis__note' }, [Drupal.t('The page has no headings.')]),
      );
      this.images.replaceChildren(
        h('h4', { class: 'neo-seo-analysis__heading' }, [Drupal.t('Image alt text')]),
        ...(images.length ? this.checks(images) : [h('p', { class: 'neo-seo-analysis__note' }, [Drupal.t('The page has no images.')])]),
      );
    }

    /**
     * The headings as an indented tree, folded away under a count. It opens
     * on its own when the outline has a problem; a choice made remembers
     * itself, separately for an outline with problems and one without.
     */
    protected tree(outline: OutlineHeading[], problems: boolean): HTMLElement {
      const tree = h('ol', { class: 'neo-seo-analysis__outline', 'aria-label': Drupal.t('Headings on the page, in order') });
      outline.forEach((heading) => tree.append(this.outlineItem(heading)));
      const issues = outline.filter((heading) => heading.issue).length;
      const key = problems ? 'outlineOpen.problems' : 'outlineOpen.clean';
      const details = h('details', { class: 'neo-seo-analysis__tree' }, [
        h('summary', { class: 'neo-seo-analysis__tree-summary' }, [
          Drupal.formatPlural(outline.length, '1 heading', '@count headings'),
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
            this.onMissing();
          }
        });
        item.append(button);
      }
      else {
        label.forEach((part) => part && item.append(part));
      }
      return item;
    }

    protected group(target: HTMLElement, title: string, help: string, score: number, assessments: Assessment[], unavailable: string | null): void {
      const band = scoreBand(score);
      const heading = h('h4', { class: 'neo-seo-analysis__heading' }, [
        h('span', { class: 'neo-seo-analysis__title' }, [title, helpBadge(Drupal.t('About the @title score', { '@title': title }), help)]),
        unavailable === null
          ? h('span', { class: 'neo-seo-analysis__score', 'data-band': band }, [
            h('span', { class: 'neo-seo-analysis__dot', 'aria-hidden': 'true' }),
            Drupal.t('@score / 100', { '@score': String(score) }),
            h('span', { class: 'visually-hidden' }, [`, ${bandLabel(band)}`]),
          ])
          : null,
      ]);
      const children: Node[] = [heading];
      if (unavailable !== null) {
        children.push(h('p', { class: 'neo-seo-analysis__note' }, [unavailable]));
      }
      else {
        children.push(...this.checks(assessments));
      }
      tooltips.detach(target);
      target.replaceChildren(...children);
      tooltips.attach(target);
    }

    protected item(assessment: Assessment): HTMLElement {
      const item = h('li', { class: 'neo-seo-analysis__item', 'data-band': assessment.band }, [
        h('span', { class: 'neo-seo-analysis__dot', 'aria-hidden': 'true' }),
        h('span', { class: 'visually-hidden' }, [`${bandLabel(assessment.band)}: `]),
        h('span', { class: 'neo-seo-analysis__message' }, [assessment.message]),
      ]);
      const highlights = assessment.highlights ?? [];
      if (highlights.length && this.host.highlight) {
        const button = h('button', {
          type: 'button',
          class: 'neo-seo-analysis__mark',
          'aria-pressed': 'false',
          'aria-label': Drupal.t('Show in the preview: @message', { '@message': assessment.message }),
        }, [Drupal.t('Show')]);
        button.addEventListener('click', () => this.toggleHighlight(button, highlights));
        item.append(button);
      }
      if (assessment.band !== 'good' && this.host.fix && this.host.canFix?.(assessment.id, highlights)) {
        const fix = h('button', {
          type: 'button',
          class: 'neo-seo-analysis__mark neo-seo-analysis__fix',
          'aria-label': Drupal.t('Go to the field that fixes this: @message', { '@message': assessment.message }),
        }, [Drupal.t('Fix')]);
        fix.addEventListener('click', () => {
          const result = this.host.fix?.(assessment.id, highlights) ?? null;
          if (result) {
            this.onFixed(result);
          }
          else {
            this.onMissing();
          }
        });
        item.append(fix);
      }
      return item;
    }

    protected toggleHighlight(button: HTMLButtonElement, highlights: NeoSeoHighlight[]): void {
      const on = this.highlighted !== button;
      this.clearHighlight();
      if (on && this.host.highlight?.(highlights)) {
        this.highlighted = button;
        button.setAttribute('aria-pressed', 'true');
      }
      else if (on) {
        this.onMissing();
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
   * or, for a page built in its own Alchemist layout, the two scores and the
   * way to the editor (summary()).
   */
  function mountSection(context: NeoSeoPanelContext): void {
    const config = (context.config.analysis ?? {}) as AnalysisConfig;
    const seo = context.section('seo', Drupal.t('SEO'));
    seo.classList.add('neo-seo-analysis');
    // A form without the field's sections: the inputs analysis reads join
    // its results. Moved, not copied: they stay the host's controls and save
    // the way they always did, help badge and all.
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
    const analyzer = new Analyzer(context.host, context.config, () => context.latest());
    let draw: (result: AnalysisResult, values: NeoSeoOverrides) => void;
    if (config.summary) {
      const scores = h('div', { class: 'neo-seo-analysis__summary' });
      seo.append(status, scores, editorLink(seo, config.summary.editorUrl));
      draw = (result, values) => {
        scores.replaceChildren(
          summaryScore(Drupal.t('SEO'), values.keyphrase.trim() === '' ? null : result.seo.score, Drupal.t('– no focus keyphrase')),
          summaryScore(Drupal.t('Readability'), result.readability.available ? result.readability.score : null, '–'),
        );
      };
    }
    else {
      const results = new Results(context.host, () => {
        status.textContent = Drupal.t('The preview does not show that text.');
      });
      seo.append(status, results.seo, results.outline, results.images);
      const readability = context.section('readability', Drupal.t('Readability'));
      readability.classList.add('neo-seo-analysis');
      readability.append(results.readability);
      draw = (result) => results.draw(result);
    }
    analyzer.onBusy((busy, failed) => {
      if (busy) {
        status.textContent = Drupal.t('Analysing…');
      }
      else if (failed) {
        status.textContent = Drupal.t('Analysis is not available in this browser.');
      }
    });
    analyzer.onResult((result, values) => {
      draw(result, values);
      status.textContent = values.cornerstone
        ? Drupal.t('Analysed as cornerstone content, against stricter thresholds.')
        : Drupal.t('Analysed. Scores are advice: they never stop a save.');
    });
    context.onRender(() => analyzer.analyze());
    if (context.latest()) {
      analyzer.analyze();
    }
  }

  /** One score of the summary: its traffic light, and the score. */
  function summaryScore(label: string, value: number | null, missing: string): HTMLElement {
    const band = value === null ? 'none' : scoreBand(value);
    return h('p', { class: 'neo-seo-analysis__summary-score', 'data-band': band }, [
      h('span', { class: 'neo-seo-analysis__dot', 'aria-hidden': 'true' }),
      h('span', { class: 'neo-seo-analysis__summary-label' }, [label]),
      h('strong', {}, [value === null ? missing : Drupal.t('@score / 100', { '@score': String(value) })]),
      value === null ? null : h('span', { class: 'visually-hidden' }, [`, ${bandLabel(band as Band)}`]),
    ]);
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

    // The dropdown: one view per pill. SEO holds what the page is scored
    // against and why; Readability why. Each score's meaning is behind the
    // (i) by its heading.
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
    const status = h('p', { class: 'neo-seo-analysis__status', role: 'status', 'aria-live': 'polite' }, [
      Drupal.t('Analysing…'),
    ]);
    const results = new Results(host, () => {
      status.textContent = Drupal.t('The preview does not show that text.');
    });
    type Side = 'seo' | 'readability';
    // SEO is wide: its inputs, outline and alt text beside the checks (two
    // columns where the dropdown has the room — see analysis.css). Readability
    // is one column of short lines.
    fields.classList.add('neo-seo-analysis__area--fields');
    results.seo.classList.add('neo-seo-analysis__area--checks');
    results.images.classList.add('neo-seo-analysis__area--images');
    results.outline.classList.add('neo-seo-analysis__area--outline');
    const sides: Record<Side, { label: string; size: NeoAlchemistDropdownSize; content: Node[] }> = {
      seo: {
        label: Drupal.t('SEO'),
        size: 'xl',
        content: [h('div', { class: 'neo-seo-analysis__columns' }, [fields, results.seo, results.images, results.outline])],
      },
      readability: {
        label: Drupal.t('Readability'),
        size: 'lg',
        content: [results.readability],
      },
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
      if (side !== 'seo') {
        results.clearHighlight();
      }
      current = side;
      dropdown.element.setAttribute('aria-label', sides[side].label);
      // Optional: a neo_alchemist without setSize() keeps its one width.
      dropdown.setSize?.(sides[side].size);
    };
    select('seo');
    // The SEO fields themselves — title, description, share image — are in the
    // Search & social panel: one click there from the checks about them.
    const footer = h('div', { class: 'neo-seo-analysis__footer' }, [status]);
    if (host.showPanel) {
      const edit = h('button', { type: 'button', class: 'btn btn-xs btn-outline neo-seo-analysis__panel-link' }, [
        Drupal.t('Edit search & social'),
      ]);
      edit.addEventListener('click', () => {
        dropdown.close();
        host.showPanel?.();
      });
      footer.append(edit);
    }
    body.replaceChildren(panes.seo, panes.readability, footer);
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
    // No keyphrase: the SEO pill asks for one and opens straight onto it.
    // Runs after the editor's own toggle, so the field takes the focus.
    badge.addEventListener('click', (event) => {
      const pill = (event.target as Element).closest<HTMLElement>('[data-neo-seo-score="seo"]');
      if (pill?.dataset.missing === 'true' && dropdown.isOpen()) {
        keyphraseInput()?.focus();
      }
    });

    const setPill = (pill: HTMLElement | null, score: number | null, missing = false) => {
      if (!pill) {
        return;
      }
      const value = pill.querySelector<HTMLElement>('.neo-seo-pill__value');
      pill.dataset.missing = missing ? 'true' : 'false';
      if (value) {
        value.hidden = missing;
      }
      // No keyphrase: the grey dot and the label alone; the tooltip says why.
      if (missing) {
        pill.dataset.band = 'none';
        pill.title = Drupal.t('No focus keyphrase: set one to score the page.');
        return;
      }
      const band = score === null ? 'none' : scoreBand(score);
      pill.dataset.band = band;
      if (value) {
        value.textContent = score === null ? '–' : String(score);
      }
      pill.title = score === null ? '' : `${bandLabel(band as Band)}: ${score} / 100`;
    };
    const setUpdating = (updating: boolean) => {
      [pills.seo, pills.readability].forEach((pill) => pill?.classList.toggle('is-updating', updating));
      badge.setAttribute('aria-busy', updating ? 'true' : 'false');
    };

    const analyzer = new Analyzer(host, editor.config, () => editor.latest());
    analyzer.onBusy((busy, failed) => {
      if (busy) {
        setUpdating(true);
        status.textContent = Drupal.t('Analysing…');
      }
      else if (failed) {
        setUpdating(false);
        status.textContent = Drupal.t('Analysis is not available in this browser.');
      }
    });
    badgeState = { editor, result: null, keyphraseMissing: false };
    analyzer.onResult((result, values) => {
      results.draw(result);
      if (badgeState) {
        badgeState.result = result;
        badgeState.keyphraseMissing = values.keyphrase.trim() === '';
      }
      setPill(pills.seo, result.seo.score, values.keyphrase.trim() === '');
      setPill(pills.readability, result.readability.available ? result.readability.score : null);
      scoreListeners.forEach((listener) => listener());
      setUpdating(false);
      status.textContent = values.cornerstone
        ? Drupal.t('Analysed as cornerstone content, against stricter thresholds.')
        : Drupal.t('Analysed.');
    });
    editor.onState((state) => {
      if (state === 'updating') {
        setUpdating(true);
      }
      else if (state === 'error') {
        setUpdating(false);
        status.textContent = Drupal.t('The page could not be rendered for analysis.');
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
  let openChecks: ((side: 'seo' | 'readability') => void) | null = null;

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
      const button = h('button', {
        type: 'button',
        class: 'neo-seo-pill btn btn-xs btn-outline',
        'data-band': 'none',
      }, [
        h('span', { class: 'neo-seo-pill__dot', 'aria-hidden': 'true' }),
        h('span', { class: 'neo-seo-pill__label' }, [side === 'seo' ? Drupal.t('SEO') : Drupal.t('Read')]),
        value,
      ]);
      button.setAttribute('aria-label', side === 'seo' ? Drupal.t('Show the SEO checks') : Drupal.t('Show the readability checks'));
      button.addEventListener('click', () => openChecks?.(side));
      row.append(button);
      return { side, button, value };
    });
    const update = () => {
      const state = badgeState;
      if (!state?.result) {
        return;
      }
      buttons.forEach(({ side, button, value }) => {
        const score = side === 'seo'
          ? (state.keyphraseMissing ? null : state.result!.seo.score)
          : (state.result!.readability.available ? state.result!.readability.score : null);
        button.dataset.band = score === null ? 'none' : scoreBand(score);
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
    const scores = h('p', { class: 'neo-seo-save-summary__scores' });
    const score = (label: string, value: number | null, missing: string) => {
      const band = value === null ? 'none' : scoreBand(value);
      return h('span', { class: 'neo-seo-save-summary__score', 'data-band': band }, [
        h('span', { class: 'neo-seo-analysis__dot', 'aria-hidden': 'true' }),
        `${label} `,
        h('strong', {}, [value === null ? missing : String(value)]),
        value === null ? null : h('span', { class: 'visually-hidden' }, [` / 100, ${bandLabel(band as Band)}`]),
      ]);
    };
    scores.append(
      score(Drupal.t('SEO'), state.keyphraseMissing ? null : result.seo.score, Drupal.t('– no focus keyphrase')),
      score(Drupal.t('Readability'), result.readability.available ? result.readability.score : null, '–'),
    );

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
