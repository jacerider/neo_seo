/**
 * Focus-keyphrase assessments. Each takes the run context and returns one
 * assessment, or null when it does not apply to this page.
 *
 * Thresholds follow Yoast's published guidance (see thresholds.ts).
 */

import type { Context } from '../context.ts';
import { FUNCTION_WORDS } from '../english.ts';
import {
  containsAll, countInSentence, countOccurrences, exactPositions, normalizedWords, phraseKey,
  splitSentences,
} from '../text.ts';
import type { Keyphrase } from '../text.ts';
import { maxDensity, percent } from '../thresholds.ts';
import type { Assessment, Highlight } from '../types.ts';

/** Tolerance for comparing against computed limits (3 × 7/6 is 3.4999…). */
const EPSILON = 1e-9;

type Check = (ctx: Context, keyphrase: Keyphrase) => Assessment | null;

export function keyphraseMissing(): Assessment {
  return {
    id: 'keyphraseMissing',
    band: 'problem',
    message: 'No focus keyphrase is set. Set one to run the keyphrase checks.',
  };
}

export const keyphraseLength: Check = (ctx, keyphrase) => {
  const counted = ctx.english
    ? keyphrase.words.filter((w) => !FUNCTION_WORDS.has(w)).length
    : keyphrase.words.length;
  const n = counted > 0 ? counted : keyphrase.words.length;
  const limits = ctx.english ? ctx.t.keyphraseLength : ctx.t.keyphraseLengthOtherLanguages;
  const unit = ctx.english ? 'content words' : 'words';
  if (n <= limits.goodMax) {
    return { id: 'keyphraseLength', band: 'good', message: `The keyphrase has ${n} ${unit}. Good length.` };
  }
  if (n <= limits.okMax) {
    return {
      id: 'keyphraseLength',
      band: 'ok',
      message: `The keyphrase has ${n} ${unit}, more than the recommended ${limits.goodMax}. Consider shortening it.`,
    };
  }
  return {
    id: 'keyphraseLength',
    band: 'problem',
    message: `The keyphrase has ${n} ${unit}, far more than the recommended ${limits.goodMax}. Shorten it.`,
  };
};

export const keyphraseInTitle: Check = (ctx, keyphrase) => {
  const id = 'keyphraseInTitle';
  const tokens = normalizedWords(ctx.input.title);
  if (tokens.length === 0) {
    return { id, band: 'problem', message: 'There is no SEO title to check for the keyphrase.' };
  }
  const positions = exactPositions(tokens, keyphrase.words);
  if (positions.length > 0) {
    const first = positions[0] ?? 0;
    // Leading function words ("How to …", "The …") still count as "at the start".
    const lead = tokens.slice(0, first);
    const atStart = first === 0 || (ctx.english && lead.every((w) => FUNCTION_WORDS.has(w)));
    if (atStart) {
      return { id, band: 'good', message: 'The exact keyphrase appears at the start of the SEO title.' };
    }
    return {
      id,
      band: 'ok',
      message: 'The exact keyphrase is in the SEO title, but not at the start. Move it to the beginning.',
    };
  }
  if (containsAll(tokens, keyphrase)) {
    return {
      id,
      band: 'ok',
      message: 'All the keyphrase words are in the SEO title, but not as the exact phrase. Try the exact phrase near the start.',
    };
  }
  return { id, band: 'problem', message: 'The SEO title does not contain the keyphrase. Add it, ideally at the start.' };
};

export const keyphraseInDescription: Check = (ctx, keyphrase) => {
  const id = 'keyphraseInDescription';
  const description = ctx.input.description.trim();
  if (description === '') {
    return { id, band: 'problem', message: 'There is no meta description to check for the keyphrase.' };
  }
  const count = countOccurrences(splitSentences(description), keyphrase);
  const { goodMin, goodMax } = ctx.t.descriptionKeyphrase;
  if (count === 0) {
    return { id, band: 'problem', message: 'The meta description does not contain the keyphrase. Add it.' };
  }
  if (count < goodMin || count > goodMax) {
    return {
      id,
      band: 'ok',
      message: `The keyphrase appears ${count} times in the meta description, more than the recommended ${goodMax}. Don't overdo it.`,
    };
  }
  return { id, band: 'good', message: 'The keyphrase appears in the meta description.' };
};

/** The URL's last path segment, decoded, hyphens and underscores as spaces. */
export function slugWords(url: string): string[] {
  const path = url.split(/[?#]/)[0] ?? '';
  const withoutOrigin = path.replace(/^[a-z][a-z0-9+.-]*:\/\/[^/]*/i, '').replace(/^\/\/[^/]*/, '');
  const segments = withoutOrigin.split('/').filter((s) => s !== '');
  let slug = segments[segments.length - 1] ?? '';
  try {
    slug = decodeURIComponent(slug);
  }
  catch {
    // Keep the raw segment when it is not valid percent-encoding.
  }
  return normalizedWords(slug.replace(/[-_+.~]+/g, ' '));
}

export const keyphraseInSlug: Check = (ctx, keyphrase) => {
  const id = 'keyphraseInSlug';
  const words = slugWords(ctx.input.url);
  const slug = new Set(words);
  if (slug.size === 0) {
    // The front page (or an unparseable URL) has no slug to judge.
    return null;
  }
  const required = [...new Set(keyphrase.required.flatMap((w) => w.split(/[-']/)).filter((w) => w !== ''))];
  const found = required.filter((w) => slug.has(w)).length;
  if (found === required.length) {
    // At the front: the keyphrase's words lead the slug, in order. Function
    // words are skipped, as pathauto drops them from slugs ("how-bake-bread").
    const lead = ctx.english ? words.filter((w) => !FUNCTION_WORDS.has(w)) : words;
    if (required.every((w, i) => lead[i] === w)) {
      return { id, band: 'good', message: 'The keyphrase starts the URL slug.' };
    }
    return {
      id,
      band: 'ok',
      message: 'The keyphrase is in the URL slug, but not at its start. Move it to the front.',
    };
  }
  if (required.length > 1 && found * 2 > required.length) {
    return {
      id,
      band: ctx.t.slugPartial,
      message: 'More than half of the keyphrase appears in the URL slug, but not all of it.',
    };
  }
  return { id, band: 'problem', message: 'The URL slug does not contain the keyphrase. Add it to the slug.' };
};

export const keyphraseInH1: Check = (ctx, keyphrase) => {
  const id = 'keyphraseInH1';
  const h1s = ctx.paper.headings.filter((h) => h.level === 1);
  if (h1s.length === 0) {
    // The H1 usually comes from the page template, outside the rendered body.
    return null;
  }
  if (h1s.some((h) => containsAll(normalizedWords(h.text), keyphrase))) {
    return { id, band: 'good', message: 'The H1 heading contains the keyphrase.' };
  }
  return {
    id,
    band: 'problem',
    message: 'The H1 heading does not contain the keyphrase. Add it to the main heading.',
    highlights: h1s.map((h): Highlight => ({ kind: 'heading', level: 1, text: h.text })),
  };
};

export const keyphraseInIntroduction: Check = (ctx, keyphrase) => {
  const id = 'keyphraseInIntroduction';
  const intro = ctx.paper.introduction;
  if (intro === '') {
    return { id, band: 'problem', message: 'There is no introduction paragraph. Start the text with one that uses the keyphrase.' };
  }
  const highlights: Highlight[] = [{ kind: 'paragraph', text: intro }];
  if (splitSentences(intro).some((s) => containsAll(normalizedWords(s), keyphrase))) {
    return { id, band: 'good', message: 'The keyphrase appears in the first paragraph.' };
  }
  if (containsAll(normalizedWords(intro), keyphrase)) {
    return {
      id,
      band: 'ok',
      message: 'The keyphrase words are in the first paragraph, but not in one sentence. Use them together.',
      highlights,
    };
  }
  return { id, band: 'problem', message: 'The keyphrase does not appear in the first paragraph. Add it there.', highlights };
};

export const keyphraseInSubheadings: Check = (ctx, keyphrase) => {
  const id = 'keyphraseInSubheadings';
  const subheadings = ctx.paper.headings.filter((h) => h.level === 2 || h.level === 3);
  if (subheadings.length === 0) {
    // Not applicable; subheading distribution (readability) covers structure.
    return null;
  }
  const matching = subheadings.filter((h) => containsAll(normalizedWords(h.text), keyphrase));
  const share = percent(matching.length, subheadings.length);
  const { min, max } = ctx.t.subheadingKeyphrase;
  const stats = `${matching.length} of ${subheadings.length} H2/H3 subheadings contain the keyphrase`;
  if (subheadings.length === 1 && matching.length === 1) {
    return { id, band: 'good', message: 'The H2/H3 subheading contains the keyphrase.' };
  }
  if (share < min) {
    return {
      id,
      band: 'ok',
      message: `${stats}. Use it in more of them (${min}–${max}%).`,
      highlights: subheadings.filter((h) => !matching.includes(h))
        .map((h): Highlight => ({ kind: 'heading', level: h.level, text: h.text })),
    };
  }
  if (share > max) {
    return {
      id,
      band: 'problem',
      message: `${stats}. That is too many; vary them (${min}–${max}%).`,
      highlights: matching.map((h): Highlight => ({ kind: 'heading', level: h.level, text: h.text })),
    };
  }
  return { id, band: 'good', message: `${stats}. Good.` };
};

export const keyphraseInImageAlt: Check = (ctx, keyphrase) => {
  const id = 'keyphraseInImageAlt';
  const images = ctx.paper.images;
  if (images.length === 0) {
    return { id, band: 'problem', message: 'There are no images. Add one with alt text that includes the keyphrase.' };
  }
  const matching = images.filter((img) => img.alt !== null && containsAll(normalizedWords(img.alt), keyphrase));
  const others = images.filter((img) => !matching.includes(img))
    .map((img): Highlight => ({ kind: 'image', src: img.src, alt: img.alt }));
  const { manyImages, min, max } = ctx.t.imageAlt;
  if (matching.length === 0) {
    return {
      id,
      band: 'problem',
      message: 'No image has alt text containing the keyphrase. Add it where it describes the image.',
      highlights: others,
    };
  }
  if (images.length < manyImages) {
    return { id, band: 'good', message: 'An image has alt text containing the keyphrase.' };
  }
  const share = percent(matching.length, images.length);
  const stats = `${matching.length} of ${images.length} images have alt text containing the keyphrase`;
  if (share < min) {
    return { id, band: 'ok', message: `${stats}. Use it in more of them (${min}–${max}%).`, highlights: others };
  }
  if (share > max) {
    return {
      id,
      band: 'ok',
      message: `${stats}. That is too many; describe each image instead (${min}–${max}%).`,
      highlights: matching.map((img): Highlight => ({ kind: 'image', src: img.src, alt: img.alt })),
    };
  }
  return { id, band: 'good', message: `${stats}. Good.` };
};

export const keyphraseDensity: Check = (ctx, keyphrase) => {
  const id = 'keyphraseDensity';
  const hits = ctx.sentences.filter((s) => countInSentence(normalizedWords(s), keyphrase) > 0);
  const count = countOccurrences(ctx.sentences, keyphrase);
  const total = ctx.wordCount;
  const highlights = hits.map((text): Highlight => ({ kind: 'sentence', text }));
  if (count === 0 || total === 0) {
    return { id, band: 'problem', message: 'The keyphrase does not appear in the text. Use it a few times.' };
  }
  const density = percent(count, total);
  const max = maxDensity(ctx.t, keyphrase.required.length);
  const min = ctx.t.density.min;
  const shown = `${density.toFixed(1)}% (${count} ${count === 1 ? 'time' : 'times'} in ${total} words)`;
  if (density < min - EPSILON) {
    return {
      id,
      band: 'ok',
      message: `Keyphrase density is ${shown}, below the recommended ${min}%. Use it a little more.`,
      highlights,
    };
  }
  if (density <= max + EPSILON) {
    return { id, band: 'good', message: `Keyphrase density is ${shown}. Good.`, highlights };
  }
  const message = `Keyphrase density is ${shown}, above the recommended ${round1(max)}%. Use it less.`;
  if (density <= max * ctx.t.density.okOverFactor + EPSILON) {
    return { id, band: 'ok', message, highlights };
  }
  return { id, band: 'problem', message: `${message} This looks like keyword stuffing.`, highlights };
};

export const previouslyUsedKeyphrase: Check = (ctx, keyphrase) => {
  const id = 'previouslyUsedKeyphrase';
  const used = ctx.input.usedKeyphrases;
  if (used === undefined) {
    return null;
  }
  const key = phraseKey(keyphrase.raw);
  const uses = used.filter((k) => phraseKey(k) === key).length;
  if (uses === 0) {
    return { id, band: 'good', message: "You haven't used this keyphrase on another page." };
  }
  if (uses <= ctx.t.previouslyUsed.okMax) {
    return {
      id,
      band: 'ok',
      message: 'This keyphrase is already the focus of another page. Pages competing for one keyphrase hurt each other.',
    };
  }
  return {
    id,
    band: 'problem',
    message: `This keyphrase is already the focus of ${uses} other pages. Choose a different one.`,
  };
};

function round1(n: number): string {
  return String(Math.round(n * 10) / 10);
}

/** All keyphrase checks, in display order. */
export const KEYPHRASE_CHECKS: readonly Check[] = [
  keyphraseLength,
  keyphraseInTitle,
  keyphraseInDescription,
  keyphraseInSlug,
  keyphraseInH1,
  keyphraseInIntroduction,
  keyphraseInSubheadings,
  keyphraseInImageAlt,
  keyphraseDensity,
  previouslyUsedKeyphrase,
];
