/**
 * Neo | SEO Analysis — content analysis in the SEO panel.
 *
 * A Drupal.neoSeoPanel extension: it gathers the focus keyphrase and the
 * cornerstone toggle into an "Analysis" section, runs the engine over every
 * draft render, lists the SEO and readability assessments as traffic lights,
 * and writes the two scores back through the host (quietly: a score never
 * marks the page changed and never blocks a save).
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

  /** A score's traffic light, as Yoast bands it. */
  function scoreBand(score: number): Band {
    return score >= 70 ? 'good' : score >= 40 ? 'ok' : 'problem';
  }

  const ORDER: Record<Band, number> = { problem: 0, ok: 1, good: 2 };

  /**
   * One worker per page, shared by every panel; answers go to whoever asked.
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

  class AnalysisSection {

    protected readonly context: NeoSeoPanelContext;
    protected readonly config: AnalysisConfig;
    protected readonly status: HTMLElement;
    protected readonly seo: HTMLElement;
    protected readonly readability: HTMLElement;
    protected keyphrases: Promise<string[] | undefined> | null = null;
    protected run = 0;
    protected timer: number | undefined;
    protected highlighted: HTMLButtonElement | null = null;

    constructor(context: NeoSeoPanelContext) {
      this.context = context;
      this.config = (context.config.analysis ?? {}) as AnalysisConfig;
      const body = context.section('analysis', Drupal.t('Content analysis'));
      body.classList.add('neo-seo-analysis');
      // The inputs analysis reads sit with its results. Moved, not copied:
      // they stay the host's controls and save the way they always did.
      ['keyphrase', 'cornerstone'].forEach((key) => {
        const control = context.host.control(key as NeoSeoOverrideKey);
        if (control) {
          body.append(control);
        }
      });
      this.status = h('p', { class: 'neo-seo-analysis__status', role: 'status', 'aria-live': 'polite' });
      this.seo = h('div', { class: 'neo-seo-analysis__group' });
      this.readability = h('div', { class: 'neo-seo-analysis__group' });
      body.append(this.status, this.seo, this.readability);
      this.setStatus(Drupal.t('Analysis runs when the preview is ready.'));

      context.onRender(() => this.analyze());
      context.host.onChange((change) => {
        if (change.key === 'keyphrase' || change.key === 'cornerstone') {
          window.clearTimeout(this.timer);
          this.timer = window.setTimeout(() => this.analyze(), RERUN_WAIT);
        }
      });
      if (context.latest()) {
        this.analyze();
      }
    }

    protected setStatus(message: string): void {
      this.status.textContent = message;
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

    protected async analyze(): Promise<void> {
      const render = this.context.latest();
      if (!render) {
        return;
      }
      this.run += 1;
      const run = this.run;
      const values = this.context.host.read();
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
      this.setStatus(Drupal.t('Analysing…'));
      try {
        const result = await analyze(input);
        if (run !== this.run) {
          return;
        }
        this.draw(result);
        this.setStatus(values.cornerstone
          ? Drupal.t('Analysed as cornerstone content, against stricter thresholds.')
          : Drupal.t('Analysed. Scores are advice: they never stop a save.'));
        this.context.host.write({
          seo_score: result.seo.score,
          readability_score: result.readability.available ? result.readability.score : null,
        }, { quiet: true });
      }
      catch (e) {
        console.error(e);
        if (run === this.run) {
          this.setStatus(Drupal.t('Analysis is not available in this browser.'));
        }
      }
    }

    protected draw(result: AnalysisResult): void {
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
      if (highlights.length && this.context.host.highlight) {
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
      if (on && this.context.host.highlight?.(highlights)) {
        this.highlighted = button;
        button.setAttribute('aria-pressed', 'true');
      }
      else if (on) {
        this.setStatus(Drupal.t('The preview does not show that text.'));
      }
    }

    protected clearHighlight(): void {
      if (this.highlighted) {
        this.highlighted.setAttribute('aria-pressed', 'false');
        this.highlighted = null;
        this.context.host.highlight?.(null);
      }
    }

  }

  Drupal.neoSeoPanel?.extend({
    id: 'analysis',
    mount(context) {
      new AnalysisSection(context);
    },
  });

})(Drupal);
