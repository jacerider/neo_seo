/**
 * Neo | SEO Analysis — content analysis for the SEO panel.
 *
 * Two surfaces over one Analyzer:
 * - the edit form: an "Analysis" section in the panel (a Drupal.neoSeoPanel
 *   extension) holding the focus keyphrase, the cornerstone toggle and the
 *   assessments;
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

(function (Drupal): void {

  interface AnalysisConfig {
    keyphrasesUrl?: string;
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
      this.group(this.seo, Drupal.t('SEO'), result.seo.score, result.seo.assessments, null);
      this.group(
        this.readability,
        Drupal.t('Readability'),
        result.readability.score,
        result.readability.assessments,
        result.readability.available ? null : (result.readability.message ?? Drupal.t('Readability is analysed for English only.')),
      );
      this.structure(result);
    }

    /**
     * The heading outline (its checks, then the headings as an indented tree,
     * flagged where they break it) and the image alt text check. Not scored.
     */
    protected structure(result: AnalysisResult): void {
      const { outline, headings, images } = result.structure;
      const list = h('ul', { class: 'neo-seo-analysis__list' });
      [...headings].sort((a, b) => ORDER[a.band] - ORDER[b.band]).forEach((assessment) => list.append(this.item(assessment)));
      const tree = h('ol', { class: 'neo-seo-analysis__outline', 'aria-label': Drupal.t('Headings on the page, in order') });
      outline.forEach((heading) => tree.append(this.outlineItem(heading)));
      this.outline.replaceChildren(
        h('h4', { class: 'neo-seo-analysis__heading' }, [Drupal.t('Heading outline')]),
        list,
        outline.length ? tree : h('p', { class: 'neo-seo-analysis__note' }, [Drupal.t('The page has no headings.')]),
      );
      if (!images.length) {
        this.images.replaceChildren(
          h('h4', { class: 'neo-seo-analysis__heading' }, [Drupal.t('Image alt text')]),
          h('p', { class: 'neo-seo-analysis__note' }, [Drupal.t('The page has no images.')]),
        );
        return;
      }
      const imageList = h('ul', { class: 'neo-seo-analysis__list' });
      images.forEach((assessment) => imageList.append(this.item(assessment)));
      this.images.replaceChildren(h('h4', { class: 'neo-seo-analysis__heading' }, [Drupal.t('Image alt text')]), imageList);
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

    protected group(target: HTMLElement, title: string, score: number, assessments: Assessment[], unavailable: string | null): void {
      const band = scoreBand(score);
      const heading = h('h4', { class: 'neo-seo-analysis__heading' }, [
        title,
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
        const list = h('ul', { class: 'neo-seo-analysis__list' });
        [...assessments].sort((a, b) => ORDER[a.band] - ORDER[b.band]).forEach((assessment) => {
          list.append(this.item(assessment));
        });
        children.push(list);
      }
      target.replaceChildren(...children);
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

  /** What the two scores mean: the same words in both surfaces. */
  function scoresHelp(): HTMLElement {
    return h('p', { class: 'neo-seo-analysis__help' }, [
      Drupal.t('SEO: how well the page is set up to be found for its focus keyphrase. Readability: how easy the text is to read. Green 70+, orange 40–69, red below. They are advice: they never stop you saving.'),
    ]);
  }

  /**
   * The edit form's Analysis section.
   */
  function mountSection(context: NeoSeoPanelContext): void {
    const body = context.section('analysis', Drupal.t('Content analysis'));
    body.classList.add('neo-seo-analysis');
    body.append(scoresHelp());
    // The inputs analysis reads sit with its results. Moved, not copied: they
    // stay the host's controls and save the way they always did.
    (['keyphrase', 'cornerstone'] as NeoSeoOverrideKey[]).forEach((key) => {
      const control = context.host.control(key);
      if (control) {
        body.append(control);
      }
    });
    const status = h('p', { class: 'neo-seo-analysis__status', role: 'status', 'aria-live': 'polite' }, [
      Drupal.t('Analysis runs when the preview is ready.'),
    ]);
    const results = new Results(context.host, () => {
      status.textContent = Drupal.t('The preview does not show that text.');
    });
    body.append(status, results.element);
    const analyzer = new Analyzer(context.host, context.config, () => context.latest());
    analyzer.onBusy((busy, failed) => {
      if (busy) {
        status.textContent = Drupal.t('Analysing…');
      }
      else if (failed) {
        status.textContent = Drupal.t('Analysis is not available in this browser.');
      }
    });
    analyzer.onResult((result, values) => {
      results.draw(result);
      status.textContent = values.cornerstone
        ? Drupal.t('Analysed as cornerstone content, against stricter thresholds.')
        : Drupal.t('Analysed. Scores are advice: they never stop a save.');
    });
    context.onRender(() => analyzer.analyze());
    if (context.latest()) {
      analyzer.analyze();
    }
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

    // The dropdown: one tab per pill. SEO holds what the score means, what it
    // is scored against and why; Readability what its score means and why.
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
    const sides: Record<Side, { label: string; help: string; content: Node[] }> = {
      seo: {
        label: Drupal.t('SEO'),
        help: Drupal.t('SEO: how well the page is set up to be found for its focus keyphrase. Green 70+, orange 40–69, red below. It is advice: it never stops you saving.'),
        content: [fields, results.seo, results.outline, results.images],
      },
      readability: {
        label: Drupal.t('Readability'),
        help: Drupal.t('Readability: how easy the text is to read. Green 70+, orange 40–69, red below. It is advice: it never stops you saving.'),
        content: [results.readability],
      },
    };
    // No tabs: the pills are the switch. The open pill's side is shown; the
    // other pill swaps it; the open pill again closes (neo_alchemist).
    const panes = {} as Record<Side, HTMLElement>;
    (Object.keys(sides) as Side[]).forEach((side) => {
      const pane = h('div', {
        id: `neo-seo-analysis-pane-${side}`,
        class: 'neo-seo-analysis__pane',
        role: 'group',
        'aria-label': sides[side].label,
      }, [h('p', { class: 'neo-seo-analysis__help' }, [sides[side].help]), ...sides[side].content]);
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
      dropdown.element.setAttribute('aria-label', sides[side].label);
    };
    select('seo');
    body.replaceChildren(panes.seo, panes.readability, status);
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
      const value = pill.querySelector('.neo-seo-pill__value');
      pill.dataset.missing = missing ? 'true' : 'false';
      if (missing) {
        pill.dataset.band = 'none';
        if (value) {
          value.textContent = Drupal.t('– set keyphrase');
        }
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
      // In Alchemist the analysis lives in the top bar badge.
      if (context.host.kind === 'form') {
        mountSection(context);
      }
    },
  });

  Drupal.neoSeoPanel?.extendBadge({
    id: 'analysis',
    mount: mountBadge,
  });

})(Drupal);
