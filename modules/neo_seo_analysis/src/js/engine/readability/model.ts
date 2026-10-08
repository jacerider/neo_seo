/**
 * The readability view of a page: body sentences grouped into runs that a
 * subheading interrupts. Headings themselves are not prose and are left out
 * of sentence-level checks.
 */

import type { Context } from '../context.ts';
import { normalizedWords, splitSentences } from '../text.ts';

export interface Sentence {
  text: string;
  /** Normalized words. */
  tokens: string[];
  /** Index of the section (text between subheadings) the sentence is in. */
  section: number;
}

export interface ReadabilityModel {
  sentences: Sentence[];
  /** Words in body paragraphs (headings excluded). */
  wordCount: number;
}

export function buildModel(ctx: Context): ReadabilityModel {
  const sentences: Sentence[] = [];
  let section = 0;
  let wordCount = 0;
  for (const block of ctx.paper.blocks) {
    if (block.kind === 'heading') {
      section++;
      continue;
    }
    for (const text of splitSentences(block.text)) {
      const tokens = normalizedWords(text);
      wordCount += tokens.length;
      sentences.push({ text, tokens, section });
    }
  }
  return { sentences, wordCount };
}
