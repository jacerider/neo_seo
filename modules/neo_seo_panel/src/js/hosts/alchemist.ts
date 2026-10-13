/**
 * The Alchemist host: the overrides live in the shared layout draft.
 *
 * The controls are the same widget elements, rendered into the side panel by
 * the SeoPanel plugin's form, which is never submitted; some may be moved into
 * another root (the top bar dropdown, see addRoot()). An editor's change is
 * written to the panel's draft key (only the overrides they touched, merged
 * over what the draft already holds), so it goes live on Publish with the
 * layout and Revert/Reset discard it. Renders come from neo_seo's draft render
 * endpoint, which applies the layout draft and this key.
 */

import * as controls from '../controls';
import { debounce } from '../dom';

/** How long typing must pause before the draft is written. */
const WRITE_WAIT = 400;

export class AlchemistHost implements NeoSeoPanelHost {

  readonly kind = 'alchemist';

  /** What the panel's draft key holds, as last read or written. */
  protected stored: Partial<NeoSeoOverrides> = {};
  protected hasDraft = false;
  /** Overrides the editor changed in this session. */
  protected readonly touched = new Set<NeoSeoOverrideKey>();
  /** Values the panel computed (scores): kept only beside a draft. */
  protected computed: Partial<NeoSeoOverrides> = {};
  protected writing: Promise<void> = Promise.resolve();
  protected readonly ready: Promise<void>;
  protected readonly listeners: Array<(change: NeoSeoHostChange) => void> = [];
  protected readonly writeSoon = debounce(() => {
    this.writing = this.writing.then(() => this.persist());
  }, WRITE_WAIT);

  protected readonly roots: HTMLElement[] = [];
  protected readonly scope: controls.Scope;
  protected readonly api: NeoAlchemistPanelApi;
  protected readonly config: NeoSeoPanelConfig;

  constructor(form: HTMLElement, api: NeoAlchemistPanelApi, config: NeoSeoPanelConfig) {
    this.api = api;
    this.config = config;
    this.scope = controls.scopeOf(this.roots);
    // The form is only a container for the controls.
    form.addEventListener('submit', (event) => event.preventDefault());
    this.addRoot(form);

    this.ready = api.get().then((state) => {
      this.stored = state.data && typeof state.data === 'object' ? state.data as Partial<NeoSeoOverrides> : {};
      this.hasDraft = state.hasDraft;
    });
    api.onDraftChange((change) => {
      this.hasDraft = change.hasDraft;
      if (change.reason === 'layout') {
        this.notify({ source: 'content' });
      }
    });
  }

  /**
   * Another element override controls live in: their edits draft the same way.
   */
  addRoot(root: HTMLElement): void {
    if (this.roots.includes(root)) {
      return;
    }
    this.roots.push(root);
    const listener = (event: Event) => {
      const key = controls.keyOf(event.target);
      if (!key) {
        return;
      }
      this.touched.add(key);
      this.writeSoon();
      this.notify({ source: 'override', key });
    };
    root.addEventListener('input', listener);
    root.addEventListener('change', listener);
  }

  read(): NeoSeoOverrides {
    return controls.read(this.scope);
  }

  write(values: Partial<NeoSeoOverrides>, options: { quiet?: boolean } = {}): void {
    controls.write(this.scope, values, false);
    if (options.quiet) {
      this.computed = { ...this.computed, ...values };
    }
    else {
      // An editor's write, like typing: drafted, and heard by the panel.
      (Object.keys(values) as NeoSeoOverrideKey[]).forEach((key) => {
        this.touched.add(key);
        this.notify({ source: 'override', key });
      });
    }
    this.writeSoon();
  }

  control(key: NeoSeoOverrideKey): HTMLElement | null {
    return controls.wrapper(this.scope, key);
  }

  async render(): Promise<NeoSeoDraftRender> {
    // The endpoint renders what the draft holds, so write first.
    this.writeSoon.flush();
    await this.ready;
    await this.writing;
    if (!this.config.renderUrl) {
      throw new Error('No draft render URL.');
    }
    const response = await fetch(this.config.renderUrl, {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    });
    if (!response.ok) {
      throw new Error(`The draft render failed (${response.status}).`);
    }
    return await response.json() as NeoSeoDraftRender;
  }

  onChange(callback: (change: NeoSeoHostChange) => void): void {
    this.listeners.push(callback);
  }

  highlight(targets: NeoSeoHighlight[] | null): boolean {
    return highlightInPreviews(targets);
  }

  /**
   * Takes the editor to where an assessment is fixed.
   *
   * An SEO field (title, description, keyphrase) is focused where it lives:
   * the panel, or wherever the keyphrase control was moved. Anything else is
   * looked for in the canvas: the first highlight found there names its
   * component (the nearest [data-component-uuid]), and the editor opens that
   * component's form at the field holding it — located by the component's
   * own preview, through the same map its click-to-field uses. A component
   * with nothing locatable is only selected.
   */
  fix(id: string, highlights: NeoSeoHighlight[]): NeoSeoFixResult {
    const field = FIELD_FIXES[id];
    if (field) {
      return this.focusControl(field) ? 'field' : null;
    }
    const editor = Drupal.neoAlchemist?.editor;
    if (!editor) {
      return null;
    }
    for (const doc of previewDocuments()) {
      for (const target of highlights) {
        const element = findTargets(doc, target)[0];
        const component = element?.closest<HTMLElement>('[data-component-uuid]');
        const uuid = component?.dataset.componentUuid;
        if (!element || !uuid) {
          continue;
        }
        const prop = element.closest<HTMLElement>('[data-neo-prop]')?.dataset.neoProp;
        const locate = locateFor(element);
        const focused = prop
          ? editor.focus({ uuid, prop })
          : editor.focus(locate ? { uuid, locate } : { uuid });
        if (focused) {
          return prop || locate ? 'component-field' : 'component';
        }
      }
    }
    return null;
  }

  canFix(id: string, highlights: NeoSeoHighlight[]): boolean {
    return !!FIELD_FIXES[id] || highlights.length > 0;
  }

  /**
   * Opens the side panel the SEO fields live in, when it is closed.
   */
  showPanel(): boolean {
    return this.openPanelOf(controls.input(this.scope, 'title'));
  }

  /**
   * Opens the side panel holding an element, when it is closed.
   */
  protected openPanelOf(element: HTMLElement | null): boolean {
    const panel = element?.closest<HTMLElement>('[data-alchemist-sidepanel]');
    if (!panel) {
      return false;
    }
    if (panel.style.display === 'none') {
      document.querySelector<HTMLElement>(`.neo-alchemist--sidepanel-toggle[data-alchemist-sidepanel="${panel.dataset.alchemistSidepanel}"]`)?.click();
    }
    return true;
  }

  /**
   * Focuses an SEO control, opening the panel when the control lives there.
   */
  protected focusControl(key: NeoSeoOverrideKey): boolean {
    const input = controls.input(this.scope, key);
    if (!input) {
      return false;
    }
    this.openPanelOf(input);
    // After the panel has had a frame to show and mount.
    window.setTimeout(() => {
      input.scrollIntoView({ block: 'center' });
      input.focus();
    }, 50);
    return true;
  }

  protected notify(change: NeoSeoHostChange): void {
    this.listeners.forEach((listener) => {
      try {
        listener(change);
      }
      catch (e) {
        console.error(e);
      }
    });
  }

  /**
   * Writes the draft key: what it held, the touched overrides as they are now,
   * and — only when there is a draft to ride along with — the scores.
   */
  protected async persist(): Promise<void> {
    await this.ready;
    const values = this.read();
    const next: Partial<NeoSeoOverrides> = { ...this.stored };
    this.touched.forEach((key) => {
      (next as Record<string, unknown>)[key] = values[key];
    });
    if (this.hasDraft || this.touched.size) {
      Object.assign(next, this.computed);
    }
    if (JSON.stringify(next) === JSON.stringify(this.stored)) {
      return;
    }
    try {
      const result = await this.api.set(next);
      this.stored = next;
      this.hasDraft = result.hasDraft;
    }
    catch (e) {
      // The panel shows why (a colleague moved the draft on); the edit stays
      // in the controls, so nothing is lost silently.
      console.error(e);
    }
  }

}

const MARK = 'data-neo-seo-highlight';

/** Assessments fixed in an SEO field rather than on the page. */
const FIELD_FIXES: Record<string, NeoSeoOverrideKey> = {
  keyphraseInTitle: 'title',
  titleWidth: 'title',
  keyphraseInDescription: 'description',
  metaDescriptionLength: 'description',
  keyphraseMissing: 'keyphrase',
  keyphraseLength: 'keyphrase',
  previouslyUsedKeyphrase: 'keyphrase',
};

/** The documents of the preview frames on show. */
function previewDocuments(): Document[] {
  return Object.values(Drupal.neoAlchemist?.iframes ?? {})
    .filter((frame): frame is HTMLIFrameElement => !!frame && frame.offsetParent !== null)
    .map((frame) => frame.contentDocument)
    .filter((doc): doc is Document => !!doc?.body);
}

/**
 * What the component's edit preview should look for to find this element's
 * field: its tag, plus its src, href or text.
 */
function locateFor(element: Element): NeoAlchemistLocate | null {
  const tag = element.tagName.toLowerCase();
  if (tag === 'img') {
    const src = element.getAttribute('src');
    return src ? { tag, src } : null;
  }
  if (tag === 'a') {
    const href = element.getAttribute('href');
    return href ? { tag, href } : null;
  }
  const text = normalize(element.textContent);
  return text ? { tag, text: text.slice(0, 200) } : null;
}

/**
 * Marks highlight targets in the editor's preview frames: the smallest element
 * holding each target is outlined, and the first is scrolled into view.
 * Best effort — a target the preview does not show is skipped.
 */
function highlightInPreviews(targets: NeoSeoHighlight[] | null): boolean {
  const frames = Object.values(Drupal.neoAlchemist?.iframes ?? {})
    .filter((frame): frame is HTMLIFrameElement => !!frame && frame.offsetParent !== null);
  let first: { frame: HTMLIFrameElement; element: Element } | null = null;
  frames.forEach((frame) => {
    const doc = frame.contentDocument;
    if (!doc?.body) {
      return;
    }
    doc.querySelectorAll(`[${MARK}]`).forEach((element) => element.removeAttribute(MARK));
    if (!doc.getElementById('neo-seo-highlight-style')) {
      const style = doc.createElement('style');
      style.id = 'neo-seo-highlight-style';
      style.textContent = `[${MARK}]{outline:3px solid #f59e0b!important;outline-offset:3px;background-color:rgb(253 230 138 / .55)!important;}`;
      doc.head.append(style);
    }
    (targets ?? []).forEach((target) => {
      findTargets(doc, target).forEach((element) => {
        element.setAttribute(MARK, '');
        first ??= { frame, element };
      });
    });
  });
  if (first) {
    scrollToMark(first);
  }
  return first !== null;
}

function pathOf(src: string): string {
  try {
    return new URL(src, window.location.href).pathname;
  }
  catch {
    return '';
  }
}

function normalize(text: string | null | undefined): string {
  return (text ?? '').replace(/\s+/g, ' ').trim().toLowerCase();
}

function findTargets(doc: Document, target: NeoSeoHighlight): Element[] {
  switch (target.kind) {
    case 'heading': {
      const text = normalize(target.text);
      return Array.from(doc.querySelectorAll(`h${target.level}`)).filter((element) => normalize(element.textContent) === text);
    }
    case 'image': {
      // By path: the canvas and the draft render may differ in host or query.
      const path = pathOf(target.src);
      return Array.from(doc.querySelectorAll('img')).filter((image) => {
        const src = image.getAttribute('src') ?? '';
        return src === target.src || (path !== '' && pathOf(src) === path);
      });
    }
    case 'link':
      return Array.from(doc.querySelectorAll('a')).filter((link) => link.getAttribute('href') === target.href);
    default: {
      // A sentence or paragraph: the innermost block that contains it.
      const text = normalize(target.text);
      if (!text) {
        return [];
      }
      const blocks = Array.from(doc.body.querySelectorAll('p, li, h1, h2, h3, h4, h5, h6, td, th, blockquote, figcaption, dd, dt'))
        .filter((element) => normalize(element.textContent).includes(text));
      return blocks.filter((block) => !blocks.some((other) => other !== block && block.contains(other))).slice(0, 1);
    }
  }
}

/**
 * The frames are sized to their content, so the editor scrolls, not the frame.
 */
function scrollToMark(mark: { frame: HTMLIFrameElement; element: Element }): void {
  let scroller: HTMLElement | null = mark.frame.parentElement;
  while (scroller && !(scroller.scrollHeight > scroller.clientHeight && /(auto|scroll)/.test(getComputedStyle(scroller).overflowY))) {
    scroller = scroller.parentElement;
  }
  const top = mark.frame.getBoundingClientRect().top + mark.element.getBoundingClientRect().top;
  if (scroller) {
    scroller.scrollTo({ top: scroller.scrollTop + top - scroller.getBoundingClientRect().top - 120, behavior: 'instant' as ScrollBehavior });
  }
  else {
    window.scrollTo({ top: window.scrollY + top - 120, behavior: 'instant' as ScrollBehavior });
  }
}
