/**
 * Internal and outbound link assessments.
 *
 * A link is internal when it is relative, or absolute on the site's host
 * (`siteHost`, else the host of an absolute page `url`); "www." is ignored.
 * Fragment-only, mailto:, tel:, javascript: and data: links are not counted.
 * A link with rel="nofollow" (or sponsored/ugc) counts as a nofollowed link.
 */

import type { Context } from '../context.ts';
import type { Link } from '../paper.ts';
import type { Assessment, Highlight } from '../types.ts';

export type LinkClass = 'internal' | 'outbound' | 'ignored';

const SCHEME = /^([a-z][a-z0-9+.-]*):/i;

function bareHost(host: string): string {
  return host.toLowerCase().replace(/^www\./, '').replace(/\.$/, '');
}

/** The site's host, from siteHost or an absolute page URL; '' when unknown. */
export function siteHostOf(siteHost: string | undefined, pageUrl: string): string {
  if (siteHost !== undefined && siteHost.trim() !== '') {
    const raw = siteHost.trim();
    try {
      return bareHost(new URL(SCHEME.test(raw) ? raw : `https://${raw}`).hostname);
    }
    catch {
      return bareHost(raw);
    }
  }
  try {
    return bareHost(new URL(pageUrl).hostname);
  }
  catch {
    return '';
  }
}

export function classifyLink(href: string, host: string): LinkClass {
  const h = href.trim();
  if (h === '' || h.startsWith('#')) {
    return 'ignored';
  }
  const scheme = SCHEME.exec(h)?.[1]?.toLowerCase();
  if (scheme !== undefined && scheme !== 'http' && scheme !== 'https') {
    return 'ignored';
  }
  if (scheme === undefined && !h.startsWith('//')) {
    return 'internal';
  }
  let linkHost: string;
  try {
    linkHost = bareHost(new URL(h.startsWith('//') ? `https:${h}` : h).hostname);
  }
  catch {
    return 'ignored';
  }
  return host !== '' && linkHost === host ? 'internal' : 'outbound';
}

function isNofollow(link: Link): boolean {
  return /\b(?:nofollow|sponsored|ugc)\b/.test(link.rel);
}

function linkAssessment(ctx: Context, kind: 'internal' | 'outbound'): Assessment {
  const id = kind === 'internal' ? 'internalLinks' : 'outboundLinks';
  const host = siteHostOf(ctx.input.siteHost, ctx.input.url);
  const links = ctx.paper.links.filter((l) => classifyLink(l.href, host) === kind);
  const followed = links.filter((l) => !isNofollow(l));
  const label = kind === 'internal' ? 'internal' : 'outbound';
  if (links.length === 0) {
    const advice = kind === 'internal'
      ? 'Link to related pages on this site.'
      : 'Link to relevant sources on other sites.';
    return { id, band: 'problem', message: `There are no ${label} links. ${advice}` };
  }
  if (followed.length === 0) {
    return {
      id,
      band: 'ok',
      message: `All ${links.length} ${label} links are nofollowed. Let search engines follow at least one.`,
      highlights: links.map((l): Highlight => ({ kind: 'link', href: l.href, text: l.text })),
    };
  }
  return {
    id,
    band: 'good',
    message: `There ${links.length === 1 ? 'is 1' : `are ${links.length}`} ${label} ${links.length === 1 ? 'link' : 'links'}. Good.`,
  };
}

export function internalLinks(ctx: Context): Assessment {
  return linkAssessment(ctx, 'internal');
}

export function outboundLinks(ctx: Context): Assessment {
  return linkAssessment(ctx, 'outbound');
}
