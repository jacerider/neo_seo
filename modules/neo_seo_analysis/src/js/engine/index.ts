/**
 * neo_seo content-analysis engine — public entry point.
 *
 * A pure function from rendered HTML plus page metadata to assessments: no
 * DOM, no window, no Drupal. It runs unchanged in a Web Worker and in node.
 *
 * Assessments are written from Yoast's published descriptions of its free
 * checks, and their thresholds follow Yoast's published guidance; no Yoast
 * code is used (ADR 0003).
 */

import { createContext } from './context.ts';
import { analyzeReadability } from './readability/index.ts';
import { analyzeSeo } from './seo/index.ts';
import type { AnalysisInput, AnalysisResult } from './types.ts';

export type {
  AnalysisInput, AnalysisResult, Assessment, Band, Highlight, ReadabilityResult, SeoResult,
} from './types.ts';

export function analyze(input: AnalysisInput): AnalysisResult {
  const ctx = createContext(normalizeInput(input));
  return {
    seo: analyzeSeo(ctx),
    readability: analyzeReadability(ctx),
  };
}

/** Defensive copy: callers on the far side of a worker may send nulls. */
function normalizeInput(input: AnalysisInput): AnalysisInput {
  const str = (v: unknown): string => (typeof v === 'string' ? v : '');
  const normalized: AnalysisInput = {
    html: str(input.html),
    title: str(input.title),
    description: str(input.description),
    url: str(input.url),
    keyphrase: str(input.keyphrase),
    cornerstone: input.cornerstone === true,
    language: str(input.language),
  };
  if (typeof input.siteHost === 'string') {
    normalized.siteHost = input.siteHost;
  }
  if (Array.isArray(input.usedKeyphrases)) {
    normalized.usedKeyphrases = input.usedKeyphrases.filter((k): k is string => typeof k === 'string');
  }
  return normalized;
}
