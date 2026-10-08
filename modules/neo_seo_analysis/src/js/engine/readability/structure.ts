/**
 * Structural readability checks: sentence length, paragraph length and
 * subheading distribution. Thresholds follow Yoast's published guidance
 * (thresholds.ts).
 */

import type { Context } from '../context.ts';
import { words } from '../text.ts';
import { percent } from '../thresholds.ts';
import type { Assessment, Highlight } from '../types.ts';
import type { ReadabilityModel } from './model.ts';

export function sentenceLength(ctx: Context, model: ReadabilityModel): Assessment {
  const id = 'sentenceLength';
  const { words: limit, goodMax, okMax } = ctx.t.sentenceLength;
  const long = model.sentences.filter((s) => s.tokens.length > limit);
  const share = percent(long.length, model.sentences.length);
  const highlights = long.map((s): Highlight => ({ kind: 'sentence', text: s.text }));
  const stats = `${round1(share)}% of the sentences have more than ${limit} words`;
  if (share <= goodMax) {
    return { id, band: 'good', message: `${stats}, within the recommended maximum of ${goodMax}%. Good.` };
  }
  return {
    id,
    band: share <= okMax ? 'ok' : 'problem',
    message: `${stats}, more than the recommended maximum of ${goodMax}%. Shorten some of them.`,
    highlights,
  };
}

export function paragraphLength(ctx: Context): Assessment {
  const id = 'paragraphLength';
  const { okWords, problemWords } = ctx.t.paragraphLength;
  const long = ctx.paper.paragraphs
    .map((p) => ({ text: p.text, n: words(p.text).length }))
    .filter((p) => p.n > okWords);
  if (long.length === 0) {
    return { id, band: 'good', message: `No paragraph is longer than ${okWords} words. Good.` };
  }
  const tooLong = long.some((p) => p.n > problemWords);
  return {
    id,
    band: tooLong ? 'problem' : 'ok',
    message: `${long.length} ${long.length === 1 ? 'paragraph is' : 'paragraphs are'} longer than the recommended maximum of ${okWords} words. Split them up.`,
    highlights: long.map((p): Highlight => ({ kind: 'paragraph', text: p.text })),
  };
}

/**
 * Sections are the runs of text between subheadings (H2–H6); the H1 is the
 * page title, not a subheading. Text before the first subheading is a section.
 */
export function subheadingDistribution(ctx: Context): Assessment {
  const id = 'subheadingDistribution';
  const { okWords, problemWords } = ctx.t.subheadingDistribution;
  const sections: { heading: Highlight | null; first: string; words: number }[] = [
    { heading: null, first: '', words: 0 },
  ];
  let subheadings = 0;
  for (const block of ctx.paper.blocks) {
    if (block.kind === 'heading') {
      if (block.level >= 2) {
        subheadings++;
        sections.push({ heading: { kind: 'heading', level: block.level, text: block.text }, first: '', words: 0 });
      }
      continue;
    }
    const section = sections[sections.length - 1];
    if (section !== undefined) {
      section.words += words(block.text).length;
      if (section.first === '') {
        section.first = block.text;
      }
    }
  }
  const total = sections.reduce((sum, s) => sum + s.words, 0);

  if (subheadings === 0) {
    if (total <= okWords) {
      return { id, band: 'good', message: 'The text is short enough not to need subheadings.' };
    }
    return {
      id,
      band: 'problem',
      message: `The text has ${total} words and no subheadings. Break it up with subheadings.`,
    };
  }

  const long = sections.filter((s) => s.words > okWords);
  if (long.length === 0) {
    return { id, band: 'good', message: 'Subheadings are spread well through the text.' };
  }
  return {
    id,
    band: long.some((s) => s.words > problemWords) ? 'problem' : 'ok',
    message: `${long.length} ${long.length === 1 ? 'section is' : 'sections are'} longer than ${okWords} words without a subheading. Add subheadings to break ${long.length === 1 ? 'it' : 'them'} up.`,
    highlights: long.map((s): Highlight => s.heading ?? { kind: 'paragraph', text: s.first }),
  };
}

function round1(n: number): number {
  return Math.round(n * 10) / 10;
}
