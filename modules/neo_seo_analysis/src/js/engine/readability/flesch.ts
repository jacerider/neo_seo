/**
 * Flesch reading ease (Rudolf Flesch, 1948):
 *
 *   206.835 − 1.015 × (words / sentences) − 84.6 × (syllables / words)
 *
 * Higher is easier. The score can exceed 100 or go below 0 for extreme
 * texts; the band uses the raw score, the message shows it clamped to 0–100.
 * Bands follow Yoast's published guidance: 60+ good, 50–60 ok, below 50 problem.
 */

import type { Context } from '../context.ts';
import { countSyllables } from '../text.ts';
import type { Thresholds } from '../thresholds.ts';
import type { Assessment, Band } from '../types.ts';
import type { ReadabilityModel } from './model.ts';

export interface FleschStats {
  words: number;
  sentences: number;
  syllables: number;
  score: number;
}

export function fleschFormula(words: number, sentences: number, syllables: number): number {
  if (words === 0 || sentences === 0) {
    return 0;
  }
  return 206.835 - 1.015 * (words / sentences) - 84.6 * (syllables / words);
}

/** Flesch statistics over already-split sentences of normalized words. */
export function fleschStats(sentences: readonly (readonly string[])[]): FleschStats {
  let words = 0;
  let syllables = 0;
  let count = 0;
  for (const tokens of sentences) {
    if (tokens.length === 0) {
      continue;
    }
    count++;
    words += tokens.length;
    for (const token of tokens) {
      syllables += countSyllables(token);
    }
  }
  return { words, sentences: count, syllables, score: fleschFormula(words, count, syllables) };
}

export function fleschBand(score: number, t: Thresholds): Band {
  if (score >= t.flesch.good) {
    return 'good';
  }
  return score >= t.flesch.ok ? 'ok' : 'problem';
}

function label(score: number): string {
  if (score >= 90) return 'very easy';
  if (score >= 80) return 'easy';
  if (score >= 70) return 'fairly easy';
  if (score >= 60) return 'plain English';
  if (score >= 50) return 'fairly difficult';
  if (score >= 30) return 'difficult';
  return 'very difficult';
}

export function fleschReadingEase(ctx: Context, model: ReadabilityModel): Assessment {
  const stats = fleschStats(model.sentences.map((s) => s.tokens));
  const band = fleschBand(stats.score, ctx.t);
  const shown = Math.min(100, Math.max(0, Math.round(stats.score * 10) / 10));
  const advice = band === 'good' ? 'Good.' : 'Try shorter sentences and shorter words.';
  return {
    id: 'fleschReadingEase',
    band,
    message: `The text scores ${shown} on the Flesch reading ease test, which is ${label(stats.score)}. ${advice}`,
  };
}
