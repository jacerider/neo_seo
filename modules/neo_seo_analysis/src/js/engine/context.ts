/**
 * The shared, precomputed view of one analysis run that assessments read.
 */

import { buildPaper } from './paper.ts';
import type { Paper } from './paper.ts';
import { isEnglish, parseKeyphrase, splitSentences, words } from './text.ts';
import type { Keyphrase } from './text.ts';
import { thresholds } from './thresholds.ts';
import type { Thresholds } from './thresholds.ts';
import type { AnalysisInput } from './types.ts';

export interface Context {
  input: AnalysisInput;
  paper: Paper;
  english: boolean;
  /** null when no keyphrase is set. */
  keyphrase: Keyphrase | null;
  t: Thresholds;
  /** Sentences of every block, headings included, in document order. */
  sentences: string[];
  /** Words in the whole text, headings included. */
  wordCount: number;
}

export function createContext(input: AnalysisInput): Context {
  const paper = buildPaper(input.html);
  const english = isEnglish(input.language);
  return {
    input,
    paper,
    english,
    keyphrase: parseKeyphrase(input.keyphrase, english),
    t: thresholds(input.cornerstone),
    sentences: paper.blocks.flatMap((b) => splitSentences(b.text)),
    wordCount: words(paper.text).length,
  };
}
