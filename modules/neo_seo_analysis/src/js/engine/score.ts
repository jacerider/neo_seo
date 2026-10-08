/**
 * Overall scores.
 *
 * Each assessment contributes points by band — good 100, ok 50, problem 0 —
 * and the score is the rounded mean over the assessments that ran. No
 * assessments means 0. So a page with every check good scores 100, one with
 * every check a problem scores 0, and each check weighs the same.
 */

import type { Assessment, Band } from './types.ts';

export const BAND_POINTS: Readonly<Record<Band, number>> = { good: 100, ok: 50, problem: 0 };

export function overallScore(assessments: readonly Assessment[]): number {
  if (assessments.length === 0) {
    return 0;
  }
  let total = 0;
  for (const a of assessments) {
    total += BAND_POINTS[a.band];
  }
  return Math.round(total / assessments.length);
}
