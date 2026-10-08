/**
 * The four previews: Google desktop and mobile, a Facebook/LinkedIn card and
 * an X summary_large_image card. Each is drawn at the width the real thing has
 * and scaled down to fit the panel (see fit()), so truncation and wrapping are
 * the real ones rather than the panel's.
 */

import { h } from './dom';
import { cut, DESCRIPTION_FONT, DESCRIPTION_LIMIT, TITLE_FONT, TITLE_LIMIT } from './measure';

export interface PreviewData {
  title: string;
  description: string;
  socialTitle: string;
  url: string;
  siteName: string;
  favicon: string | null;
  image: NeoSeoDraftRender['head']['image'];
  noindex: boolean;
}

export interface PreviewKind {
  id: 'google' | 'mobile' | 'facebook' | 'x';
  label: string;
  /** What the preview shows, for assistive technology. */
  name: string;
  /** The real thing's width in CSS pixels. */
  width: number;
  draw(data: PreviewData): HTMLElement;
}

function host(url: string): string {
  try {
    return new URL(url, window.location.href).host;
  }
  catch {
    return url;
  }
}

/** Google's breadcrumb-style URL: "https://example.com › insights › slug". */
function breadcrumb(url: string): string {
  try {
    const parsed = new URL(url, window.location.href);
    const segments = parsed.pathname.split('/').filter(Boolean).map((segment) => {
      try {
        return decodeURIComponent(segment);
      }
      catch {
        return segment;
      }
    });
    return [`${parsed.protocol}//${parsed.host}`, ...segments].join(' › ');
  }
  catch {
    return url;
  }
}

function favicon(data: PreviewData, className: string): HTMLElement {
  const box = h('span', { class: className, 'aria-hidden': 'true' });
  if (data.favicon) {
    box.append(h('img', { src: data.favicon, alt: '', width: '18', height: '18' }));
  }
  return box;
}

function siteLine(data: PreviewData, prefix: string): HTMLElement {
  return h('div', { class: `${prefix}__site` }, [
    favicon(data, `${prefix}__favicon`),
    h('span', { class: `${prefix}__source` }, [
      h('span', { class: `${prefix}__name` }, [data.siteName || host(data.url)]),
      h('span', { class: `${prefix}__url` }, [breadcrumb(data.url)]),
    ]),
  ]);
}

function shareImage(data: PreviewData, className: string): HTMLElement {
  const box = h('div', { class: className });
  if (data.image?.url) {
    box.append(h('img', { src: data.image.url, alt: data.image.alt ?? '', loading: 'lazy' }));
  }
  else {
    box.classList.add('is-empty');
    box.append(h('span', {}, [Drupal.t('No share image')]));
  }
  return box;
}

function noindexNote(data: PreviewData): HTMLElement | null {
  return data.noindex
    ? h('p', { class: 'neo-seo-preview__note' }, [Drupal.t('Hidden from search results: this page asks search engines not to index it.')])
    : null;
}

export const PREVIEWS: PreviewKind[] = [
  {
    id: 'google',
    label: 'Google',
    name: Drupal.t('Google desktop search result preview'),
    width: 600,
    draw(data) {
      return h('div', { class: 'neo-seo-g' }, [
        siteLine(data, 'neo-seo-g'),
        h('div', { class: 'neo-seo-g__title' }, [cut(data.title, TITLE_FONT, TITLE_LIMIT).text]),
        h('div', { class: 'neo-seo-g__desc' }, [cut(data.description, DESCRIPTION_FONT, DESCRIPTION_LIMIT).text]),
        noindexNote(data),
      ]);
    },
  },
  {
    id: 'mobile',
    label: 'Mobile',
    name: Drupal.t('Google mobile search result preview'),
    width: 380,
    draw(data) {
      return h('div', { class: 'neo-seo-g neo-seo-g--mobile' }, [
        siteLine(data, 'neo-seo-g'),
        h('div', { class: 'neo-seo-g__title' }, [data.title]),
        h('div', { class: 'neo-seo-g__desc' }, [data.description]),
        noindexNote(data),
      ]);
    },
  },
  {
    id: 'facebook',
    label: 'Facebook',
    name: Drupal.t('Facebook and LinkedIn share card preview'),
    width: 500,
    draw(data) {
      return h('div', { class: 'neo-seo-fb' }, [
        shareImage(data, 'neo-seo-fb__image'),
        h('div', { class: 'neo-seo-fb__body' }, [
          h('div', { class: 'neo-seo-fb__host' }, [host(data.url).toUpperCase()]),
          h('div', { class: 'neo-seo-fb__title' }, [data.socialTitle]),
          h('div', { class: 'neo-seo-fb__desc' }, [data.description]),
        ]),
      ]);
    },
  },
  {
    id: 'x',
    label: 'X',
    name: Drupal.t('X share card preview'),
    width: 500,
    draw(data) {
      const image = shareImage(data, 'neo-seo-x__image');
      image.append(h('span', { class: 'neo-seo-x__title' }, [data.socialTitle]));
      return h('div', { class: 'neo-seo-x' }, [
        image,
        h('div', { class: 'neo-seo-x__from' }, [Drupal.t('From @host', { '@host': host(data.url) })]),
      ]);
    },
  },
];

/**
 * Scales a preview drawn at its real width down to the frame's width, and
 * keeps the frame as tall as the scaled preview.
 */
export function fit(frame: HTMLElement, stage: HTMLElement, naturalWidth: number): () => void {
  stage.style.width = `${naturalWidth}px`;
  const apply = () => {
    const available = frame.clientWidth;
    if (!available) {
      return;
    }
    const scale = Math.min(1, available / naturalWidth);
    stage.style.transform = scale < 1 ? `scale(${scale})` : '';
    frame.style.height = `${Math.ceil(stage.offsetHeight * scale)}px`;
  };
  const observer = new ResizeObserver(apply);
  observer.observe(frame);
  observer.observe(stage);
  apply();
  return () => observer.disconnect();
}
