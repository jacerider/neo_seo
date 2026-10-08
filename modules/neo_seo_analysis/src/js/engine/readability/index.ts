/**
 * The readability side of the analysis. English only: for any other language
 * it returns `available: false` and says so, never scores computed with
 * English rules.
 */

import type { Context } from '../context.ts';
import { overallScore } from '../score.ts';
import type { Assessment, ReadabilityResult } from '../types.ts';
import { fleschReadingEase } from './flesch.ts';
import { sentenceBeginnings, transitionWords } from './flow.ts';
import { buildModel } from './model.ts';
import { passiveVoice } from './passive.ts';
import { paragraphLength, sentenceLength, subheadingDistribution } from './structure.ts';

export function analyzeReadability(ctx: Context): ReadabilityResult {
  if (!ctx.english) {
    const language = ctx.input.language.trim() === '' ? 'not set' : `"${ctx.input.language.trim()}"`;
    return {
      available: false,
      score: 0,
      assessments: [],
      message: `Readability analysis is only available for English. This page's language is ${language}.`,
    };
  }

  const model = buildModel(ctx);
  if (model.sentences.length === 0) {
    const assessments: Assessment[] = [
      { id: 'textPresence', band: 'problem', message: 'There is no text to analyze yet. Add some content.' },
    ];
    return { available: true, score: overallScore(assessments), assessments };
  }

  const assessments: Assessment[] = [
    fleschReadingEase(ctx, model),
    sentenceLength(ctx, model),
    paragraphLength(ctx),
    subheadingDistribution(ctx),
    passiveVoice(ctx, model),
  ];
  const transitions = transitionWords(ctx, model);
  if (transitions !== null) {
    assessments.push(transitions);
  }
  assessments.push(sentenceBeginnings(ctx, model));
  return { available: true, score: overallScore(assessments), assessments };
}
