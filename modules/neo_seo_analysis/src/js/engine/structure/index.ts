/**
 * Page structure: the heading outline and image alt text.
 *
 * Reported beside the SEO and readability results, not folded into either
 * score: they describe how the page is built for search engines and screen
 * readers, and each finding points at something to change.
 */

import type { Context } from '../context.ts';
import type { Assessment, Highlight, OutlineHeading, StructureResult } from '../types.ts';

export function analyzeStructure(ctx: Context): StructureResult {
  const outline = outlineOf(ctx);
  const headings = [singleH1(outline), headingLevels(outline)].filter((a): a is Assessment => a !== null);
  const images = [imageAltText(ctx)].filter((a): a is Assessment => a !== null);
  return { outline, headings, images };
}

/**
 * The page's headings in order, each flagged when it breaks the outline: an
 * H1 after the first, or a level more than one deeper than the heading
 * before it.
 */
function outlineOf(ctx: Context): OutlineHeading[] {
  let seenH1 = false;
  let previous = 0;
  return ctx.paper.headings.map((heading) => {
    const entry: OutlineHeading = { level: heading.level, text: heading.text };
    if (heading.level === 1) {
      if (seenH1) {
        entry.issue = 'extraH1';
      }
      seenH1 = true;
    }
    else if (previous > 0 && heading.level > previous + 1) {
      entry.issue = 'skippedLevel';
    }
    previous = heading.level;
    return entry;
  });
}

const headingHighlight = (h: OutlineHeading): Highlight => ({ kind: 'heading', level: h.level, text: h.text });

export function singleH1(outline: OutlineHeading[]): Assessment {
  const id = 'singleH1';
  const h1s = outline.filter((h) => h.level === 1);
  if (h1s.length === 0) {
    return { id, band: 'problem', message: 'The page has no H1 heading. Give it one main heading.' };
  }
  if (h1s.length > 1) {
    return {
      id,
      band: 'problem',
      message: `The page has ${h1s.length} H1 headings. Keep one main heading and make the others H2.`,
      highlights: h1s.map(headingHighlight),
    };
  }
  return { id, band: 'good', message: 'The page has one H1 heading. Good.' };
}

export function headingLevels(outline: OutlineHeading[]): Assessment | null {
  const id = 'headingLevels';
  if (outline.length < 2) {
    return null;
  }
  const skipped = outline
    .map((h, i) => ({ h, before: outline[i - 1] }))
    .filter(({ h }) => h.issue === 'skippedLevel');
  if (skipped.length === 0) {
    return { id, band: 'good', message: 'Heading levels follow each other in order.' };
  }
  const first = skipped[0]!;
  const example = `H${first.before?.level ?? 0} → H${first.h.level} at “${first.h.text}”`;
  return {
    id,
    band: 'ok',
    message: skipped.length === 1
      ? `A heading skips a level (${example}). Don't skip levels.`
      : `${skipped.length} headings skip a level, e.g. ${example}. Don't skip levels.`,
    highlights: skipped.map(({ h }) => headingHighlight(h)),
  };
}

/** A file name as words: no directories, extensions or separators. */
function fileWords(src: string): string {
  const base = src.split(/[?#]/)[0]!.split('/').pop() ?? '';
  let name = base;
  try {
    name = decodeURIComponent(base);
  }
  catch {
    // Keep the raw name.
  }
  return name.split('.')[0]!.replace(/[-_\s]+/g, ' ').trim().toLowerCase();
}

/** Alt text that says nothing: missing, empty, or the file's own name. */
export function altIsMissing(src: string, alt: string | null): boolean {
  if (alt === null || alt.trim() === '') {
    return true;
  }
  const words = alt.split('.')[0]!.replace(/[-_\s]+/g, ' ').trim().toLowerCase();
  return words !== '' && words === fileWords(src);
}

export function imageAltText(ctx: Context): Assessment | null {
  const id = 'imageAltText';
  const images = ctx.paper.images;
  if (images.length === 0) {
    return null;
  }
  const missing = images.filter((img) => altIsMissing(img.src, img.alt));
  if (missing.length === 0) {
    return {
      id,
      band: 'good',
      message: images.length === 1 ? 'The image has alt text.' : `All ${images.length} images have alt text.`,
    };
  }
  return {
    id,
    band: 'problem',
    message: `${missing.length} of ${images.length} images have no alt text, or use the file name. Describe what each one shows.`,
    highlights: missing.map((img): Highlight => ({ kind: 'image', src: img.src, alt: img.alt })),
  };
}
