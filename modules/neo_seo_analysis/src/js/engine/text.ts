/**
 * Text utilities: word and sentence tokenizing, English syllable counting and
 * focus-keyphrase matching.
 *
 * Matching rules (ticket 15): case- and diacritic-insensitive, whole-word, and
 * no stemming — "cats" never matches "cat".
 */

import { ABBREVIATIONS, FUNCTION_WORDS } from './english.ts';

const WORD = /[\p{L}\p{N}]+(?:['’\-][\p{L}\p{N}]+)*/gu;
const COMBINING_MARKS = /\p{M}+/gu;

/** Lowercase, strip diacritics, unify apostrophes. */
export function normalizeWord(word: string): string {
  return word.normalize('NFD').replace(COMBINING_MARKS, '').toLowerCase().replace(/’/g, "'");
}

/** The words of a text, as written. Hyphenated and apostrophe words stay whole. */
export function words(text: string): string[] {
  return text.match(WORD) ?? [];
}

/** The words of a text, normalized for matching. */
export function normalizedWords(text: string): string[] {
  return words(text).map(normalizeWord);
}

export function isEnglish(language: string): boolean {
  return /^en(?:[-_]|$)/i.test(language.trim());
}

const TERMINATOR = /[.!?…]+['"”’)\]]*(?=\s|$)/g;
const LAST_TOKEN = /(\S+)$/;

/**
 * Split one block of text into sentences. A block boundary always ends a
 * sentence, so callers split per block. Abbreviations ("Dr.", "e.g.") and a
 * following lowercase letter do not end a sentence.
 */
export function splitSentences(text: string): string[] {
  const sentences: string[] = [];
  let start = 0;
  TERMINATOR.lastIndex = 0;
  let m: RegExpExecArray | null;
  while ((m = TERMINATOR.exec(text)) !== null) {
    const end = m.index + m[0].length;
    const after = text.slice(end).trimStart();
    if (m[0] === '.') {
      const before = LAST_TOKEN.exec(text.slice(start, m.index))?.[1] ?? '';
      const bare = normalizeWord(before).replace(/^[("'“‘[]+/, '');
      if (ABBREVIATIONS.has(bare) || /^\p{Lu}$/u.test(before)) {
        continue;
      }
    }
    if (after !== '' && /^\p{Ll}/u.test(after)) {
      continue;
    }
    const sentence = text.slice(start, end).trim();
    if (sentence !== '') {
      sentences.push(sentence);
    }
    start = end;
  }
  const rest = text.slice(start).trim();
  if (rest !== '') {
    sentences.push(rest);
  }
  return sentences.filter((s) => words(s).length > 0);
}

/** Hand-checked counts for common words the rules below get wrong. */
const SYLLABLE_EXCEPTIONS: Record<string, number> = {
  area: 3, areas: 3, idea: 3, ideas: 3, ideal: 3, business: 2, businesses: 3, every: 2,
  everyone: 3, everything: 3, friend: 1, friends: 1, friendly: 2, recipe: 3, recipes: 3,
  people: 2, poem: 2, poet: 2, quiet: 2, real: 1, really: 2, create: 2, created: 3,
  creates: 2, creating: 3, creative: 3, being: 2, simile: 3, coyote: 3, cafe: 2, naive: 2,
  sometimes: 2, someone: 2, something: 2, somewhere: 2, whereas: 2, therefore: 2, are: 1,
  were: 1, there: 1, where: 1, here: 1, more: 1, before: 2, maybe: 2, lives: 1, eye: 1,
  eyes: 1, fire: 1, hour: 1, our: 1, million: 2, billion: 2, union: 2, opinion: 3, lion: 2,
  onion: 2, giant: 2, society: 4, variety: 4, anxiety: 4, experience: 4, experienced: 4,
  experiences: 5, period: 3, serious: 3, series: 2,
};

/**
 * Estimate the syllables in one English word. A rule-based heuristic, accurate
 * for the large majority of everyday words; numbers count as one syllable.
 */
export function countSyllables(word: string): number {
  const w = normalizeWord(word).replace(/[^a-z]/g, '');
  if (w === '') {
    return 1;
  }
  const known = SYLLABLE_EXCEPTIONS[w];
  if (known !== undefined) {
    return known;
  }
  if (w.length <= 3) {
    return 1;
  }

  let count = (w.match(/[aeiouy]+/g) ?? []).length;

  // Silent endings.
  if (/[^aeiouy]e$/.test(w) && !/[^aeiouy]le$/.test(w)) {
    count--; // make, prove (but table, little keep their -le syllable)
  }
  else if (/[^aeiouy]ed$/.test(w) && !/[td]ed$/.test(w)) {
    count--; // used, filled (but wanted, ended)
  }
  else if (/[^aeiouy]es$/.test(w) && !/(?:[sxzcg]|ch|sh)es$/.test(w) && !/[^aeiouy]les$/.test(w)) {
    count--; // makes, notes (but boxes, pages, tables)
  }
  // Silent e before a suffix: lovely, statement, useful, kindness, hopeless.
  if (/[^aeiouy]e(?:ly|ment|ments|ful|ness|less)$/.test(w)) {
    count--;
  }

  // Vowel pairs that are two syllables (hiatus).
  if (/[aeiouy]ing$/.test(w)) {
    count++; // doing, saying, seeing
  }
  if (/(?:^|[^cgts])ia/.test(w)) {
    count++; // media, trial, Australian (but special, Asian)
  }
  if (/(?:^|[^cgtsxnl])io/.test(w)) {
    count++; // radio, period, various (but nation, region, vision)
  }
  if (/(?:^|[^cftr])ie[nt]/.test(w) || /scie[nt]/.test(w)) {
    count++; // client, diet, science, audience (but ancient, efficient, friend)
  }
  if (/(?:^|[^g])eo(?!u)/.test(w)) {
    count++; // video, theory, stereo (but gorgeous, pigeon)
  }
  if (/^(?:react|reali[sz]|realit)/.test(w)) {
    count++;
  }
  return Math.max(1, count);
}

/** A focus keyphrase, normalized for matching. */
export interface Keyphrase {
  raw: string;
  /** Every word, normalized, in order (for exact-phrase matching). */
  words: string[];
  /**
   * The words that must all be present for an any-order match: the content
   * words (function words dropped, English only), or every word if the
   * keyphrase is made only of function words.
   */
  required: string[];
}

export function parseKeyphrase(raw: string, english: boolean): Keyphrase | null {
  // Quotes around a keyphrase are a Yoast convention for "exact"; we treat
  // them as decoration.
  const all = normalizedWords(raw.replace(/["“”]/g, ''));
  if (all.length === 0) {
    return null;
  }
  const content = english ? all.filter((w) => !FUNCTION_WORDS.has(w)) : all;
  const required = [...new Set(content.length > 0 ? content : all)];
  return { raw: raw.trim(), words: all, required };
}

/** Every required keyphrase word appears among the tokens, in any order. */
export function containsAll(tokens: readonly string[], keyphrase: Keyphrase): boolean {
  const set = new Set(tokens);
  return keyphrase.required.every((w) => set.has(w));
}

/** Indexes where the exact keyphrase starts in the token list. */
export function exactPositions(tokens: readonly string[], phrase: readonly string[]): number[] {
  const found: number[] = [];
  if (phrase.length === 0) {
    return found;
  }
  for (let i = 0; i + phrase.length <= tokens.length; i++) {
    let ok = true;
    for (let j = 0; j < phrase.length; j++) {
      if (tokens[i + j] !== phrase[j]) {
        ok = false;
        break;
      }
    }
    if (ok) {
      found.push(i);
    }
  }
  return found;
}

/**
 * How many times the keyphrase occurs in one sentence: the number of complete
 * sets of its required words (so "dog food for dogs and dog food" counts 2 for
 * "dog food" — every word must be there each time).
 */
export function countInSentence(tokens: readonly string[], keyphrase: Keyphrase): number {
  let min = Infinity;
  for (const word of keyphrase.required) {
    let n = 0;
    for (const t of tokens) {
      if (t === word) {
        n++;
      }
    }
    min = Math.min(min, n);
  }
  return Number.isFinite(min) ? min : 0;
}

/** Keyphrase occurrences across a list of sentences. */
export function countOccurrences(sentences: readonly string[], keyphrase: Keyphrase): number {
  let total = 0;
  for (const sentence of sentences) {
    total += countInSentence(normalizedWords(sentence), keyphrase);
  }
  return total;
}

/** Normalized comparison key for a whole phrase (used-before checks). */
export function phraseKey(text: string): string {
  return normalizedWords(text.replace(/["“”]/g, '')).join(' ');
}
