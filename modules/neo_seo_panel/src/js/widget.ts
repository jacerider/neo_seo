/**
 * The SEO panel: previews and length feedback over the overrides controls.
 *
 * It knows its host only through NeoSeoPanelHost — never the edit form or the
 * Alchemist editor — so one widget serves both. It asks the host for a draft
 * render whenever something that changes the page changes, on a debounce and
 * one at a time, and draws the previews from the render's head values: what
 * the page's head will print, template plus unsaved override.
 */

import { debounce, h, uniqueId } from './dom';
import { cut, DESCRIPTION_FONT, DESCRIPTION_LIMIT, TITLE_FONT, TITLE_LIMIT, width } from './measure';
import { fit, PREVIEWS, type PreviewData, type PreviewKind } from './previews';
import { suggestDescription, type SuggestBlock } from './suggest';

/** Overrides that change nothing the draft render shows. */
const RENDERLESS: NeoSeoOverrideKey[] = ['keyphrase', 'cornerstone', 'seo_score', 'readability_score'];

/** How long typing must pause before the page is rendered again. */
const OVERRIDE_WAIT = 600;
const CONTENT_WAIT = 1500;

type Band = 'good' | 'ok' | 'problem' | 'none';

interface Meter {
  element: HTMLElement;
  bar: HTMLElement;
  text: HTMLElement;
}

export class SeoPanel {

  protected render: NeoSeoDraftRender | null = null;
  protected rendering = false;
  protected again = false;
  protected readonly renderListeners: Array<(render: NeoSeoDraftRender) => void> = [];
  protected readonly mountedExtensions = new Set<string>();
  protected readonly stages = new Map<PreviewKind['id'], HTMLElement>();
  protected readonly meters: Partial<Record<'title' | 'description', Meter>> = {};
  protected suggestion: { element: HTMLElement; text: HTMLElement } | null = null;
  /** The render the paragraphs below were read from. */
  protected suggestFrom: NeoSeoDraftRender | null = null;
  protected suggestBlocks: SuggestBlock[] = [];
  protected status!: HTMLElement;
  protected sections!: HTMLElement;
  protected schema!: HTMLElement;
  protected readonly renderSoon = debounce(() => this.refresh(), OVERRIDE_WAIT);
  protected readonly renderLater = debounce(() => this.refresh(), CONTENT_WAIT);

  protected readonly root: HTMLElement;
  protected readonly host: NeoSeoPanelHost;
  protected readonly config: NeoSeoPanelConfig;
  protected readonly source: NeoSeoEditor | null;

  /**
   * @param source
   *   An editor that renders for the panel (Alchemist): the panel then only
   *   draws what it renders. Without one the panel asks its host itself.
   */
  constructor(root: HTMLElement, host: NeoSeoPanelHost, config: NeoSeoPanelConfig, source: NeoSeoEditor | null = null) {
    this.root = root;
    this.host = host;
    this.config = config;
    this.source = source;
    this.build();
    this.host.onChange((change) => this.changed(change));
    if (source) {
      source.onRender((render) => this.received(render));
      source.onState((state) => {
        if (state === 'updating') {
          this.setStatus(Drupal.t('Updating preview…'));
        }
        else if (state === 'error') {
          this.setStatus(Drupal.t('The preview could not be updated.'), true);
        }
      });
      const latest = source.latest();
      if (latest) {
        this.received(latest);
      }
      return;
    }
    // After every behavior has attached: the host's own wiring (Drupal's AJAX
    // on the edit form) may attach after the panel does.
    window.setTimeout(() => this.refresh(), 0);
  }

  /**
   * Mounts an extension: it gets the host, a section and every render.
   */
  extend(extension: NeoSeoPanelExtension): void {
    if (this.mountedExtensions.has(extension.id)) {
      return;
    }
    this.mountedExtensions.add(extension.id);
    try {
      extension.mount({
        host: this.host,
        config: this.config,
        section: (id, title) => this.section(id, title),
        top: (element) => {
          this.root.parentElement?.prepend(element);
        },
        latest: () => this.render,
        onRender: (callback) => {
          this.renderListeners.push(callback);
        },
      });
    }
    catch (e) {
      console.error(e);
    }
  }

  protected build(): void {
    const tabsId = uniqueId('neo-seo-previews');
    const tablist = h('div', {
      class: 'neo-seo-panel__tabs',
      role: 'tablist',
      'aria-label': Drupal.t('Preview'),
    });
    const panels = h('div', { class: 'neo-seo-panel__previews' });
    PREVIEWS.forEach((kind, index) => {
      const tabId = `${tabsId}-${kind.id}-tab`;
      const panelId = `${tabsId}-${kind.id}`;
      const tab = h('button', {
        type: 'button',
        role: 'tab',
        id: tabId,
        class: 'neo-seo-panel__tab',
        'aria-controls': panelId,
        'aria-selected': index === 0 ? 'true' : 'false',
        tabindex: index === 0 ? '0' : '-1',
        title: kind.name,
      }, [kind.label]);
      const stage = h('div', { class: 'neo-seo-panel__stage' });
      const frame = h('div', { class: 'neo-seo-panel__frame' }, [stage]);
      const panel = h('div', {
        role: 'tabpanel',
        id: panelId,
        class: 'neo-seo-panel__preview',
        'aria-label': kind.name,
      }, [frame]);
      panel.hidden = index !== 0;
      tablist.append(tab);
      panels.append(panel);
      this.stages.set(kind.id, stage);
      fit(frame, stage, kind.width);
    });
    tablist.addEventListener('click', (event) => {
      const tab = (event.target as Element).closest<HTMLElement>('[role="tab"]');
      if (tab) {
        this.select(tablist, tab);
      }
    });
    tablist.addEventListener('keydown', (event) => {
      const tabs = Array.from(tablist.querySelectorAll<HTMLElement>('[role="tab"]'));
      const current = tabs.indexOf(document.activeElement as HTMLElement);
      const step = { ArrowRight: 1, ArrowLeft: -1 }[event.key];
      if (current >= 0 && step) {
        event.preventDefault();
        const next = tabs[(current + step + tabs.length) % tabs.length];
        this.select(tablist, next);
        next.focus();
      }
    });

    this.status = h('p', { class: 'neo-seo-panel__status', role: 'status', 'aria-live': 'polite' });
    this.schema = h('p', { class: 'neo-seo-panel__schema' });
    const heading = h('h3', { class: 'neo-seo-panel__heading' }, [Drupal.t('Search and share previews')]);
    this.root.replaceChildren(
      h('section', { class: 'neo-seo-panel__card', 'aria-label': Drupal.t('Search and share previews') }, [
        h('div', { class: 'neo-seo-panel__head' }, [heading, this.status]),
        tablist,
        panels,
        this.schema,
      ]),
    );

    this.meters.title = this.meter('title');
    this.meters.description = this.meter('description');
    this.suggestion = this.suggestBox();

    // Extension sections follow the controls.
    this.sections = h('div', { class: 'neo-seo-panel__sections' });
    this.root.parentElement?.append(this.sections);
    this.setStatus(Drupal.t('Rendering preview…'));
  }

  protected select(tablist: HTMLElement, selected: HTMLElement): void {
    tablist.querySelectorAll<HTMLElement>('[role="tab"]').forEach((tab) => {
      const active = tab === selected;
      tab.setAttribute('aria-selected', active ? 'true' : 'false');
      tab.tabIndex = active ? 0 : -1;
      const panel = document.getElementById(tab.getAttribute('aria-controls') ?? '');
      if (panel) {
        panel.hidden = !active;
      }
    });
  }

  /**
   * A length meter under the title or description control.
   */
  protected meter(key: 'title' | 'description'): Meter | undefined {
    const control = this.host.control(key);
    if (!control) {
      return undefined;
    }
    const id = uniqueId(`neo-seo-meter-${key}`);
    const bar = h('span', { class: 'neo-seo-meter__bar' });
    const text = h('span', { class: 'neo-seo-meter__text' });
    const element = h('div', { class: 'neo-seo-meter', id }, [
      h('span', { class: 'neo-seo-meter__track', 'aria-hidden': 'true' }, [bar]),
      text,
    ]);
    control.append(element);
    const field = control.querySelector('input, textarea');
    if (field) {
      const describedBy = field.getAttribute('aria-describedby');
      field.setAttribute('aria-describedby', describedBy ? `${describedBy} ${id}` : id);
    }
    return { element, bar, text };
  }

  /**
   * The suggested description under the description field: shown while the
   * description comes from the template, filled into the field on request.
   */
  protected suggestBox(): { element: HTMLElement; text: HTMLElement } | null {
    const control = this.host.control('description');
    if (!control) {
      return null;
    }
    const text = h('p', { class: 'neo-seo-suggest__text' });
    const use = h('button', { type: 'button', class: 'neo-seo-suggest__use btn btn-xs btn-outline' }, [Drupal.t('Use this')]);
    const element = h('div', {
      class: 'neo-seo-suggest',
      role: 'group',
      'aria-label': Drupal.t('Suggested description'),
    }, [
      h('p', { class: 'neo-seo-suggest__label' }, [Drupal.t('Suggested from the page’s opening text:')]),
      text,
      use,
    ]);
    element.hidden = true;
    use.addEventListener('click', () => {
      // Into the field as if typed: the editor takes it from there.
      this.host.write({ description: text.textContent ?? '' });
      const field = control.querySelector<HTMLTextAreaElement>('textarea');
      field?.focus();
    });
    control.append(element);
    return { element, text };
  }

  protected suggest(values: NeoSeoOverrides): void {
    if (!this.suggestion) {
      return;
    }
    const render = this.render;
    if (render && render !== this.suggestFrom) {
      this.suggestFrom = render;
      this.suggestBlocks = paragraphsOf(render.html);
    }
    const suggestion = render && !values.description.trim()
      ? suggestDescription(this.suggestBlocks, {
        keyphrase: values.keyphrase,
        measure: (text) => width(text, DESCRIPTION_FONT),
        limit: DESCRIPTION_LIMIT,
      })
      : null;
    this.suggestion.text.textContent = suggestion ?? '';
    this.suggestion.element.hidden = !suggestion;
  }

  protected section(id: string, title: string): HTMLElement {
    const body = h('div', { class: 'neo-seo-panel__section-body' });
    const headingId = uniqueId(`neo-seo-section-${id}`);
    this.sections.append(h('section', {
      class: `neo-seo-panel__section neo-seo-panel__section--${id}`,
      'aria-labelledby': headingId,
    }, [
      h('h3', { class: 'neo-seo-panel__heading', id: headingId }, [title]),
      body,
    ]));
    return body;
  }

  protected changed(change: NeoSeoHostChange): void {
    if (change.source === 'override') {
      if (change.key && RENDERLESS.includes(change.key)) {
        return;
      }
      // Typed text needs no server to be previewed; the render catches up.
      this.draw();
      this.setStatus(Drupal.t('Updating preview…'));
      if (!this.source) {
        this.renderLater.cancel();
        this.renderSoon();
      }
    }
    else if (!this.source) {
      this.renderLater();
    }
  }

  /**
   * Renders the page now, or again as soon as the render in flight returns.
   */
  protected async refresh(): Promise<void> {
    if (this.rendering) {
      this.again = true;
      return;
    }
    this.rendering = true;
    this.again = false;
    try {
      this.received(await this.host.render());
    }
    catch (e) {
      console.error(e);
      this.setStatus(Drupal.t('The preview could not be updated.'), true);
    }
    finally {
      this.rendering = false;
      if (this.again) {
        this.refresh();
      }
    }
  }

  /**
   * A render arrived: draw it and hand it to the extensions.
   */
  protected received(render: NeoSeoDraftRender): void {
    this.render = render;
    this.draw();
    this.setStatus(Drupal.t('Preview of the page as it will be saved.'));
    this.renderListeners.forEach((listener) => {
      try {
        listener(render);
      }
      catch (e) {
        console.error(e);
      }
    });
  }

  protected setStatus(message: string, error = false): void {
    this.status.textContent = message;
    this.status.classList.toggle('is-error', error);
  }

  /**
   * What the previews show: the render, with typed text laid over it until
   * the next render arrives. Text with tokens waits for the server.
   */
  protected data(): PreviewData | null {
    if (!this.render) {
      return null;
    }
    const head = this.render.head;
    const values = this.host.read();
    const typed = (value: string, rendered: string) => value.trim() && !value.includes('[') ? value.trim() : rendered;
    return {
      title: typed(values.title, head.title),
      description: typed(values.description, head.description),
      socialTitle: head.social_title || head.title,
      url: head.canonical || this.render.url,
      siteName: head.site_name,
      favicon: this.config.favicon,
      image: head.image,
      noindex: values.noindex || /noindex/i.test(head.robots),
    };
  }

  protected draw(): void {
    const data = this.data();
    if (!data || !this.render) {
      return;
    }
    PREVIEWS.forEach((kind) => {
      this.stages.get(kind.id)?.replaceChildren(kind.draw(data));
    });
    const values = this.host.read();
    this.measure('title', data.title, !values.title.trim());
    this.measure('description', data.description, !values.description.trim());
    this.suggest(values);
    const head = this.render.head;
    this.schema.textContent = head.article_type && head.article_type !== 'None'
      ? Drupal.t('Schema: @page with @article', { '@page': head.page_type, '@article': head.article_type })
      : Drupal.t('Schema: @page', { '@page': head.page_type });
  }

  /**
   * Live length feedback: characters, and pixels against Google's cut.
   */
  protected measure(key: 'title' | 'description', text: string, fromTemplate: boolean): void {
    const meter = this.meters[key];
    if (!meter) {
      return;
    }
    const font = key === 'title' ? TITLE_FONT : DESCRIPTION_FONT;
    const limit = key === 'title' ? TITLE_LIMIT : DESCRIPTION_LIMIT;
    const pixels = Math.round(width(text, font));
    const chars = text.length;
    let band: Band;
    let verdict: string;
    if (!chars) {
      band = 'problem';
      verdict = key === 'title' ? Drupal.t('No title.') : Drupal.t('No description: search engines will pick some text from the page.');
    }
    else if (pixels > limit) {
      band = 'problem';
      verdict = Drupal.t('Too long: Google cuts it after about @n characters.', {
        '@n': String(cut(text, font, limit).text.length - 4),
      });
    }
    else if (key === 'title' ? pixels < limit * 0.5 : chars < 120) {
      band = 'ok';
      verdict = Drupal.t('Short: there is room for more.');
    }
    else {
      band = 'good';
      verdict = Drupal.t('Good length.');
    }
    meter.element.dataset.band = band;
    meter.bar.style.width = `${Math.min(100, Math.round((pixels / limit) * 100))}%`;
    const source = fromTemplate ? Drupal.t('From the template.') + ' ' : '';
    meter.text.textContent = `${source}${Drupal.formatPlural(chars, '1 character', '@count characters')} · ${pixels} / ${limit} px. ${verdict}`;
  }

}

/**
 * The page's paragraphs, in order, for the suggested description: page
 * chrome (navigation, header, footer, asides, forms) left out, and each with
 * the share of its text that is link text.
 */
function paragraphsOf(html: string): SuggestBlock[] {
  const doc = new DOMParser().parseFromString(html, 'text/html');
  return Array.from(doc.querySelectorAll('p, blockquote'))
    .filter((element) => !element.closest('nav, header, footer, aside, form, [role="navigation"]'))
    .map((element) => {
      const text = (element.textContent ?? '').replace(/\s+/g, ' ').trim();
      const linked = Array.from(element.querySelectorAll('a')).reduce((sum, a) => sum + (a.textContent ?? '').trim().length, 0);
      return { text, linkRatio: text ? linked / text.length : 1 };
    });
}
