/**
 * Every threshold the assessments use, in one place.
 *
 * The values follow Yoast's published guidance for its free assessments (the
 * help articles describing each check), not its source code — see ADR 0003.
 * Cornerstone content tightens text length, keyphrase-in-slug, sentence length
 * and subheading distribution, as Yoast documents; nothing else changes.
 */

import type { Band } from './types.ts';

export interface Thresholds {
  /** Content words in the keyphrase: ≤ goodMax good, ≤ okMax ok, else problem. */
  keyphraseLength: { goodMax: number; okMax: number };
  /** Same, for languages without a function-word list (all words count). */
  keyphraseLengthOtherLanguages: { goodMax: number; okMax: number };
  /** Words: ≥ good good, ≥ ok ok, else problem. */
  textLength: { good: number; ok: number };
  /** Band when only some (more than half) of the keyphrase is in the slug. */
  slugPartial: Band;
  /** SEO title characters: 0 problem, < goodMin ok, ≤ goodMax good, else problem. */
  titleLength: { goodMin: number; goodMax: number };
  /** Meta description characters: 0 problem, < goodMin ok, ≤ goodMax good, else ok. */
  descriptionLength: { goodMin: number; goodMax: number };
  /** Keyphrase occurrences in the meta description: goodMin..goodMax good, more ok, 0 problem. */
  descriptionKeyphrase: { goodMin: number; goodMax: number };
  /**
   * Keyphrase density in %: 0 occurrences problem, < min ok, ≤ max good,
   * ≤ max × okOverFactor ok, else problem. max shrinks for long keyphrases,
   * see maxDensity().
   */
  density: { min: number; max: number; okOverFactor: number };
  /** % of H2/H3 subheadings containing the keyphrase: < min ok, ≤ max good, else problem. */
  subheadingKeyphrase: { min: number; max: number };
  /** Below `manyImages` one matching alt is good; from it on, min..max % is good. */
  imageAlt: { manyImages: number; min: number; max: number };
  /** Previous uses of the keyphrase: 0 good, ≤ okMax ok, else problem. */
  previouslyUsed: { okMax: number };
  /** Flesch reading ease: ≥ good good, ≥ ok ok, else problem. */
  flesch: { good: number; ok: number };
  /** Sentences over `words` words, as %: ≤ goodMax good, ≤ okMax ok, else problem. */
  sentenceLength: { words: number; goodMax: number; okMax: number };
  /** Paragraph words: any over okWords is ok-band, any over problemWords problem. */
  paragraphLength: { okWords: number; problemWords: number };
  /** Section words between subheadings: same banding as paragraphs. */
  subheadingDistribution: { okWords: number; problemWords: number };
  /** Passive sentences, as %: ≤ goodMax good, ≤ okMax ok, else problem. */
  passiveVoice: { goodMax: number; okMax: number };
  /** Transition sentences, as %: ≥ good good, ≥ ok ok, else problem; only for ≥ minWords. */
  transitionWords: { minWords: number; good: number; ok: number };
  /** This many consecutive sentences starting with the same word is a problem. */
  sentenceBeginnings: { run: number };
}

const REGULAR: Thresholds = {
  keyphraseLength: { goodMax: 4, okMax: 8 },
  keyphraseLengthOtherLanguages: { goodMax: 6, okMax: 9 },
  textLength: { good: 300, ok: 250 },
  slugPartial: 'ok',
  titleLength: { goodMin: 40, goodMax: 60 },
  descriptionLength: { goodMin: 120, goodMax: 156 },
  descriptionKeyphrase: { goodMin: 1, goodMax: 2 },
  density: { min: 0.5, max: 3, okOverFactor: 7 / 6 },
  subheadingKeyphrase: { min: 30, max: 75 },
  imageAlt: { manyImages: 5, min: 30, max: 70 },
  previouslyUsed: { okMax: 1 },
  flesch: { good: 60, ok: 50 },
  sentenceLength: { words: 20, goodMax: 25, okMax: 30 },
  paragraphLength: { okWords: 150, problemWords: 200 },
  subheadingDistribution: { okWords: 300, problemWords: 350 },
  passiveVoice: { goodMax: 10, okMax: 15 },
  transitionWords: { minWords: 200, good: 30, ok: 20 },
  sentenceBeginnings: { run: 3 },
};

const CORNERSTONE: Thresholds = {
  ...REGULAR,
  textLength: { good: 900, ok: 400 },
  slugPartial: 'problem',
  sentenceLength: { words: 20, goodMax: 20, okMax: 25 },
  subheadingDistribution: { okWords: 250, problemWords: 300 },
};

export function thresholds(cornerstone: boolean): Thresholds {
  return cornerstone ? CORNERSTONE : REGULAR;
}

/**
 * The maximum good density for a keyphrase of `length` required words: the
 * base maximum for one or two words, then scaled down (2/length) so a long
 * phrase is not pushed into every sentence, never below 1%.
 */
export function maxDensity(t: Thresholds, length: number): number {
  return Math.max(1, t.density.max * Math.min(1, 2 / Math.max(1, length)));
}

/**
 * part / whole as a percentage, rounded to 6 decimals so exact ratios compare
 * exactly at a band boundary (7 / 10 is 70, not 70.00000000000001).
 */
export function percent(part: number, whole: number): number {
  return whole === 0 ? 0 : Math.round((part / whole) * 1e8) / 1e6;
}
