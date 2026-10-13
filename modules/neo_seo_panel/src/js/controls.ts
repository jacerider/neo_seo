/**
 * The SEO overrides controls, read and written by name.
 *
 * Both hosts render the same widget elements (neo_seo's SeoOverridesWidget),
 * so their inputs carry the same names: neo_seo[0][title] and so on. This is
 * host-side code — the panel itself only sees NeoSeoPanelHost.
 */

const PREFIX = 'neo_seo[0][';

const KEYS: NeoSeoOverrideKey[] = [
  'title', 'description', 'image', 'keyphrase', 'page_type', 'article_type',
  'noindex', 'cornerstone', 'seo_score', 'readability_score',
];

type Control = HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement;

/** Where controls are looked up: an element, or several (see scopeOf()). */
export interface Scope {
  querySelector<E extends Element = Element>(selectors: string): E | null;
}

/** One scope over several roots: the first match wins. */
export function scopeOf(roots: HTMLElement[]): Scope {
  return {
    querySelector<E extends Element = Element>(selectors: string): E | null {
      for (const root of roots) {
        const found = root.querySelector<E>(selectors);
        if (found) {
          return found;
        }
      }
      return null;
    },
  };
}

/** The override a control holds, from its name; null for anything else. */
export function keyOf(target: EventTarget | null): NeoSeoOverrideKey | null {
  const name = target instanceof Element ? target.getAttribute('name') : null;
  if (!name || !name.startsWith(PREFIX)) {
    return null;
  }
  const key = name.slice(PREFIX.length, -1) as NeoSeoOverrideKey;
  return KEYS.includes(key) ? key : null;
}

export function input(root: Scope, key: NeoSeoOverrideKey): Control | null {
  return root.querySelector<Control>(`[name="${PREFIX}${key}]"]`);
}

/** The form item wrapping a control, its description (and help badge) included. */
export function wrapper(root: Scope, key: NeoSeoOverrideKey): HTMLElement | null {
  const control = input(root, key);
  if (!control || (control instanceof HTMLInputElement && control.type === 'hidden')) {
    return null;
  }
  return control.closest<HTMLElement>('.form-item, .js-form-item')
    ?? control;
}

/** The media id in an entity autocomplete's "Label (12)". */
function mediaId(value: string): number | null {
  const match = /\((\d+)\)\s*$/.exec(value.trim());
  return match ? Number(match[1]) : null;
}

function score(value: string): number | null {
  return /^\d{1,3}$/.test(value) ? Number(value) : null;
}

export function read(root: Scope): NeoSeoOverrides {
  const text = (key: NeoSeoOverrideKey) => input(root, key)?.value ?? '';
  const checked = (key: NeoSeoOverrideKey) => {
    const control = input(root, key);
    return control instanceof HTMLInputElement ? control.checked : false;
  };
  return {
    title: text('title'),
    description: text('description'),
    image: mediaId(text('image')),
    keyphrase: text('keyphrase'),
    page_type: text('page_type'),
    article_type: text('article_type'),
    noindex: checked('noindex'),
    cornerstone: checked('cornerstone'),
    seo_score: score(text('seo_score')),
    readability_score: score(text('readability_score')),
  };
}

/**
 * Writes values into the controls. The share image is not writable: its
 * control holds a label the panel does not know.
 */
export function write(root: Scope, values: Partial<NeoSeoOverrides>, announce: boolean): void {
  (Object.keys(values) as NeoSeoOverrideKey[]).forEach((key) => {
    const control = input(root, key);
    const value = values[key];
    if (!control || key === 'image') {
      return;
    }
    if (control instanceof HTMLInputElement && control.type === 'checkbox') {
      control.checked = !!value;
    }
    else {
      control.value = value === null || value === undefined ? '' : String(value);
    }
    if (announce) {
      control.dispatchEvent(new Event('input', { bubbles: true }));
      control.dispatchEvent(new Event('change', { bubbles: true }));
    }
  });
}
