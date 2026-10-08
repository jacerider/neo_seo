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

import type { AnalysisInput, AnalysisResult, Assessment, Band } from './engine/types.ts';
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
    readonly seo: HTMLElement;
    readonly readability: HTMLElement;
    protected highlighted: HTMLButtonElement | null = null;

    constructor(host: NeoSeoPanelHost, onMissing: () => void) {
      this.host = host;
      this.onMissing = onMissing;
      this.seo = h('div', { class: 'neo-seo-analysis__group' });
      this.readability = h('div', { class: 'neo-seo-analysis__group' });
      this.element = h('div', { class: 'neo-seo-analysis__results' }, [this.seo, this.readability]);
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
        content: [fields, results.seo],
      },
      readability: {
        label: Drupal.t('Readability'),
        help: Drupal.t('Readability: how easy the text is to read. Green 70+, orange 40–69, red below. It is advice: it never stops you saving.'),
        content: [results.readability],
      },
    };
    const tablist = h('div', { class: 'neo-seo-analysis__tabs', role: 'tablist', 'aria-label': Drupal.t('Scores') });
    const tabs = {} as Record<Side, HTMLButtonElement>;
    const panes = {} as Record<Side, HTMLElement>;
    (Object.keys(sides) as Side[]).forEach((side) => {
      const tab = h('button', {
        type: 'button',
        role: 'tab',
        id: `neo-seo-analysis-tab-${side}`,
        class: 'neo-seo-analysis__tab',
        'aria-controls': `neo-seo-analysis-pane-${side}`,
        'aria-selected': 'false',
        tabindex: '-1',
      }, [sides[side].label]);
      const pane = h('div', {
        role: 'tabpanel',
        id: `neo-seo-analysis-pane-${side}`,
        class: 'neo-seo-analysis__pane',
        'aria-labelledby': tab.id,
      }, [h('p', { class: 'neo-seo-analysis__help' }, [sides[side].help]), ...sides[side].content]);
      pane.hidden = true;
      tab.addEventListener('click', () => select(side));
      tabs[side] = tab;
      panes[side] = pane;
      tablist.append(tab);
    });
    tablist.addEventListener('keydown', (event) => {
      if (event.key === 'ArrowRight' || event.key === 'ArrowLeft') {
        event.preventDefault();
        const next: Side = tabs.seo.getAttribute('aria-selected') === 'true' ? 'readability' : 'seo';
        select(next);
        tabs[next].focus();
      }
    });
    // The pill of the side on show is marked while the dropdown is open.
    const select = (side: Side) => {
      (Object.keys(tabs) as Side[]).forEach((key) => {
        const on = key === side;
        tabs[key].setAttribute('aria-selected', on ? 'true' : 'false');
        tabs[key].tabIndex = on ? 0 : -1;
        panes[key].hidden = !on;
        pills[key]?.classList.toggle('is-active', on && dropdown.isOpen());
      });
      const pill = pills[side];
      if (pill && dropdown.isOpen()) {
        dropdown.setTrigger(pill);
      }
      if (side !== 'seo') {
        results.clearHighlight();
      }
    };
    select('seo');
    body.replaceChildren(tablist, panes.seo, panes.readability, status);
    dropdown.onToggle((open, trigger) => {
      if (!open) {
        results.clearHighlight();
        pills.seo?.classList.remove('is-active');
        pills.readability?.classList.remove('is-active');
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
    analyzer.onResult((result, values) => {
      results.draw(result);
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
