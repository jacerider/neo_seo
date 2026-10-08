/**
 * Small DOM helpers for the SEO panel. Text always goes in as text, never as
 * markup: titles and descriptions are editor input.
 */

type Child = Node | string | null | undefined | false;

export function h<K extends keyof HTMLElementTagNameMap>(
  tag: K,
  attributes: Record<string, string | null | undefined> = {},
  children: Child[] = [],
): HTMLElementTagNameMap[K] {
  const element = document.createElement(tag);
  Object.entries(attributes).forEach(([name, value]) => {
    if (value !== null && value !== undefined) {
      element.setAttribute(name, value);
    }
  });
  children.forEach((child) => {
    if (child) {
      element.append(child);
    }
  });
  return element;
}

let uid = 0;

/** A document-unique id with a readable prefix. */
export function uniqueId(prefix: string): string {
  uid += 1;
  return `${prefix}-${uid}`;
}

/** Calls the callback once calls stop for `wait` ms; `flush` runs it now. */
export function debounce(callback: () => void, wait: number): { (): void; cancel(): void; flush(): void } {
  let timer: number | undefined;
  const debounced = () => {
    window.clearTimeout(timer);
    timer = window.setTimeout(() => {
      timer = undefined;
      callback();
    }, wait);
  };
  debounced.cancel = () => {
    window.clearTimeout(timer);
    timer = undefined;
  };
  debounced.flush = () => {
    if (timer !== undefined) {
      debounced.cancel();
      callback();
    }
  };
  return debounced;
}
