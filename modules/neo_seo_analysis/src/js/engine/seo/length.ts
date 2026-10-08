/**
 * Length assessments that need no keyphrase: SEO title, meta description and
 * text length. Thresholds follow Yoast's published guidance (thresholds.ts).
 */

import type { Context } from '../context.ts';
import type { Assessment } from '../types.ts';

/** Characters as the user sees them (code points, not UTF-16 units). */
function charLength(text: string): number {
  return [...text.trim()].length;
}

/**
 * SEO title length. Google truncates by pixel width (about 600px); measuring
 * pixels is the panel's job, so the engine approximates with a character
 * count (about 10px per character on average).
 */
export function titleWidth(ctx: Context): Assessment {
  const id = 'titleWidth';
  const n = charLength(ctx.input.title);
  const { goodMin, goodMax } = ctx.t.titleLength;
  if (n === 0) {
    return { id, band: 'problem', message: 'There is no SEO title. Write one.' };
  }
  if (n < goodMin) {
    return {
      id,
      band: 'ok',
      message: `The SEO title is ${n} characters, short of the ${goodMin}–${goodMax} that fit in search results. Use the space.`,
    };
  }
  if (n > goodMax) {
    return {
      id,
      band: 'problem',
      message: `The SEO title is ${n} characters and will likely be cut off in search results. Keep it under ${goodMax + 1}.`,
    };
  }
  return { id, band: 'good', message: `The SEO title is ${n} characters. Good length.` };
}

export function metaDescriptionLength(ctx: Context): Assessment {
  const id = 'metaDescriptionLength';
  const n = charLength(ctx.input.description);
  const { goodMin, goodMax } = ctx.t.descriptionLength;
  if (n === 0) {
    return {
      id,
      band: 'problem',
      message: 'There is no meta description. Search engines will show a snippet from the page instead.',
    };
  }
  if (n < goodMin) {
    return {
      id,
      band: 'ok',
      message: `The meta description is ${n} characters. Up to ${goodMax} are available; use the space.`,
    };
  }
  if (n > goodMax) {
    return {
      id,
      band: 'ok',
      message: `The meta description is ${n} characters, over ${goodMax}. It may be cut off in search results.`,
    };
  }
  return { id, band: 'good', message: `The meta description is ${n} characters. Good length.` };
}

export function textLength(ctx: Context): Assessment {
  const id = 'textLength';
  const n = ctx.wordCount;
  const { good, ok } = ctx.t.textLength;
  const kind = ctx.input.cornerstone ? 'cornerstone content' : 'a page';
  if (n >= good) {
    return { id, band: 'good', message: `The text has ${n} words. Good.` };
  }
  if (n >= ok) {
    return {
      id,
      band: 'ok',
      message: `The text has ${n} words, slightly below the recommended ${good} for ${kind}. Add a little more.`,
    };
  }
  return {
    id,
    band: 'problem',
    message: `The text has ${n} words, well below the recommended ${good} for ${kind}. Add more content.`,
  };
}
