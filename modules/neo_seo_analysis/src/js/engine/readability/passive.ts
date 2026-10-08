/**
 * Passive voice (English): a form of "to be" followed by a past participle,
 * with up to two adverbs between them ("was not built", "is often used",
 * "has been quickly approved"). Participles are the irregular list in
 * english.ts plus any word ending in -ed that is not a known non-participle.
 *
 * Known misses and false positives are pinned in the tests, not hidden:
 * get-passives ("got fired"), 's contractions ("it's thought"), reduced
 * relative clauses ("the report, written by…"), long adverb runs; adjectival
 * participles ("she was tired", "they are interested") count as passive.
 */

import type { Context } from '../context.ts';
import {
  BE_FORMS, BE_SUFFIXES, IRREGULAR_PARTICIPLES, NOT_PARTICIPLES, PASSIVE_INTERVENERS,
} from '../english.ts';
import { normalizedWords } from '../text.ts';
import { percent } from '../thresholds.ts';
import type { Assessment, Highlight } from '../types.ts';
import type { ReadabilityModel } from './model.ts';

const MAX_INTERVENERS = 2;

function isBeForm(token: string): boolean {
  return BE_FORMS.has(token) || BE_SUFFIXES.some((s) => token.length > s.length && token.endsWith(s));
}

function isParticiple(token: string): boolean {
  if (IRREGULAR_PARTICIPLES.has(token)) {
    return true;
  }
  return token.length >= 4 && token.endsWith('ed') && !NOT_PARTICIPLES.has(token);
}

function isIntervener(token: string): boolean {
  return PASSIVE_INTERVENERS.has(token) || (token.length > 3 && token.endsWith('ly'));
}

/** Whether one sentence (normalized words) contains a passive construction. */
export function isPassiveTokens(tokens: readonly string[]): boolean {
  for (let i = 0; i < tokens.length; i++) {
    if (!isBeForm(tokens[i] ?? '')) {
      continue;
    }
    let skipped = 0;
    for (let j = i + 1; j < tokens.length; j++) {
      const token = tokens[j] ?? '';
      if (isParticiple(token)) {
        return true;
      }
      if (!isIntervener(token) || ++skipped > MAX_INTERVENERS) {
        break;
      }
    }
  }
  return false;
}

export function isPassive(sentence: string): boolean {
  return isPassiveTokens(normalizedWords(sentence));
}

export function passiveVoice(ctx: Context, model: ReadabilityModel): Assessment {
  const id = 'passiveVoice';
  const passive = model.sentences.filter((s) => isPassiveTokens(s.tokens));
  const share = percent(passive.length, model.sentences.length);
  const { goodMax, okMax } = ctx.t.passiveVoice;
  const stats = `${Math.round(share * 10) / 10}% of the sentences are in the passive voice`;
  if (share <= goodMax) {
    return { id, band: 'good', message: `${stats}. Good.` };
  }
  return {
    id,
    band: share <= okMax ? 'ok' : 'problem',
    message: `${stats}, more than the recommended maximum of ${goodMax}%. Use active verbs.`,
    highlights: passive.map((s): Highlight => ({ kind: 'sentence', text: s.text })),
  };
}
