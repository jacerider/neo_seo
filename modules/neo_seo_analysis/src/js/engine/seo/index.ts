/**
 * The SEO side of the analysis: focus-keyphrase checks plus the checks that
 * need no keyphrase (lengths, links).
 */

import type { Context } from '../context.ts';
import { overallScore } from '../score.ts';
import type { Assessment, SeoResult } from '../types.ts';
import { KEYPHRASE_CHECKS, keyphraseMissing } from './keyphrase.ts';
import { metaDescriptionLength, textLength, titleWidth } from './length.ts';
import { internalLinks, outboundLinks } from './links.ts';

export function analyzeSeo(ctx: Context): SeoResult {
  const assessments: Assessment[] = [];
  const keyphrase = ctx.keyphrase;
  if (keyphrase === null) {
    assessments.push(keyphraseMissing());
  }
  else {
    for (const check of KEYPHRASE_CHECKS) {
      const result = check(ctx, keyphrase);
      if (result !== null) {
        assessments.push(result);
      }
    }
  }
  assessments.push(titleWidth(ctx), metaDescriptionLength(ctx), textLength(ctx), internalLinks(ctx), outboundLinks(ctx));
  return { score: overallScore(assessments), assessments };
}
