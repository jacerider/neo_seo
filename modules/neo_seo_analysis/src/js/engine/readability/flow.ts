/**
 * Flow checks: transition words and consecutive sentence beginnings.
 * Thresholds follow Yoast's published guidance (thresholds.ts).
 */

import type { Context } from '../context.ts';
import { BEGINNING_EXTENDERS, TRANSITION_PHRASES, TRANSITION_WORDS } from '../english.ts';
import { percent } from '../thresholds.ts';
import type { Assessment, Highlight } from '../types.ts';
import type { ReadabilityModel, Sentence } from './model.ts';

/** Whether a sentence (normalized words) contains a transition word or phrase. */
export function hasTransition(tokens: readonly string[]): boolean {
  if (tokens.some((t) => TRANSITION_WORDS.has(t))) {
    return true;
  }
  const padded = ` ${tokens.join(' ')} `;
  return TRANSITION_PHRASES.some((phrase) => padded.includes(` ${phrase} `));
}

/** Applies only to texts of at least `minWords` words; null otherwise. */
export function transitionWords(ctx: Context, model: ReadabilityModel): Assessment | null {
  const id = 'transitionWords';
  const { minWords, good, ok } = ctx.t.transitionWords;
  if (model.wordCount < minWords) {
    return null;
  }
  const share = percent(model.sentences.filter((s) => hasTransition(s.tokens)).length, model.sentences.length);
  const stats = `${Math.round(share * 10) / 10}% of the sentences contain a transition word`;
  if (share >= good) {
    return { id, band: 'good', message: `${stats}. Good.` };
  }
  return {
    id,
    band: share >= ok ? 'ok' : 'problem',
    message: `${stats}, less than the recommended ${good}%. Connect your sentences with words like "however" or "for example".`,
  };
}

/** The word(s) a sentence starts with; "The dog" and "The cat" differ. */
export function beginning(tokens: readonly string[]): string {
  const first = tokens[0] ?? '';
  const second = tokens[1];
  return BEGINNING_EXTENDERS.has(first) && second !== undefined ? `${first} ${second}` : first;
}

export function sentenceBeginnings(ctx: Context, model: ReadabilityModel): Assessment {
  const id = 'sentenceBeginnings';
  const limit = ctx.t.sentenceBeginnings.run;
  const runs: Sentence[][] = [];
  let current: Sentence[] = [];
  for (const sentence of model.sentences) {
    const prev = current[current.length - 1];
    if (prev !== undefined && prev.section === sentence.section && beginning(prev.tokens) === beginning(sentence.tokens)) {
      current.push(sentence);
      continue;
    }
    if (current.length >= limit) {
      runs.push(current);
    }
    current = [sentence];
  }
  if (current.length >= limit) {
    runs.push(current);
  }

  if (runs.length === 0) {
    return { id, band: 'good', message: 'No consecutive sentences start with the same word. Good.' };
  }
  const longest = Math.max(...runs.map((r) => r.length));
  return {
    id,
    band: 'problem',
    message: `${runs.length === 1 ? 'Once' : `${runs.length} times`}, ${limit} or more consecutive sentences start with the same word (up to ${longest} in a row). Vary them.`,
    highlights: runs.flat().map((s): Highlight => ({ kind: 'sentence', text: s.text })),
  };
}
