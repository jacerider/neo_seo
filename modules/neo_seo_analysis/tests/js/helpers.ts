/**
 * Shared fixtures for the engine tests.
 */

import assert from 'node:assert/strict';
import { analyze } from '../../src/js/engine/index.ts';
import type { AnalysisInput, AnalysisResult, Assessment } from '../../src/js/engine/index.ts';

export const BASE: AnalysisInput = {
  html: '',
  title: '',
  description: '',
  url: 'https://example.com/page',
  keyphrase: '',
  cornerstone: false,
  language: 'en',
};

export function run(input: Partial<AnalysisInput>): AnalysisResult {
  return analyze({ ...BASE, ...input });
}

/** The SEO assessment with this id, or undefined when it did not run. */
export function seo(input: Partial<AnalysisInput>, id: string): Assessment | undefined {
  return run(input).seo.assessments.find((a) => a.id === id);
}

/** The readability assessment with this id, or undefined when it did not run. */
export function readability(input: Partial<AnalysisInput>, id: string): Assessment | undefined {
  return run(input).readability.assessments.find((a) => a.id === id);
}

/** The band of an assessment that must exist. */
export function band(found: Assessment | undefined): string {
  assert.ok(found, 'expected the assessment to run');
  return found.band;
}

/** Neutral one-syllable filler words: no function, transition or be-words. */
const FILLER = [
  'red', 'fox', 'runs', 'past', 'tall', 'trees', 'near', 'cold', 'lake', 'birds', 'sing',
  'songs', 'kids', 'kick', 'balls', 'dogs', 'chase', 'cats', 'wind', 'blows', 'sand',
];

/**
 * Exactly `n` filler words in sentences of `perSentence` words, each sentence
 * capitalized and ending with a period. Words cycle through FILLER.
 */
export function filler(n: number, perSentence = 10, offset = 0): string {
  const out: string[] = [];
  let sentence: string[] = [];
  for (let i = 0; i < n; i++) {
    let w = FILLER[(i + offset) % FILLER.length] ?? 'red';
    if (sentence.length === 0) {
      w = w.charAt(0).toUpperCase() + w.slice(1);
    }
    sentence.push(w);
    if (sentence.length === perSentence) {
      out.push(sentence.join(' ') + '.');
      sentence = [];
    }
  }
  if (sentence.length > 0) {
    out.push(sentence.join(' ') + '.');
  }
  return out.join(' ');
}

export function p(text: string): string {
  return `<p>${text}</p>`;
}

/** A string of exactly n characters. */
export function chars(n: number): string {
  return 'a'.repeat(n);
}
