/**
 * A suggested meta description, taken from the page's own text.
 *
 * Extractive only: whole sentences from the opening paragraphs, as many as
 * fit Google's description width, never cut mid-word. Pure — the caller
 * turns the page into blocks and supplies the width measure — so it runs
 * the same in the panel and under node's test runner (no imports, on
 * purpose: see tests/js/suggest.test.ts).
 */

/** A paragraph of the page, in document order. */
export interface SuggestBlock {
  /** Its text; a line break (a <br>) is a newline. */
  text: string;
  /** Share of the text that is link text, 0–1: navigation reads as all links. */
  linkRatio?: number;
}

export interface SuggestOptions {
  /** The focus keyphrase; a sentence holding it is preferred. */
  keyphrase?: string;
  /** Rendered width of a text, in pixels. */
  measure: (text: string) => number;
  /** Google's desktop description width. */
  limit?: number;
  /** Fewer words than this is not a paragraph worth describing a page by. */
  minWords?: number;
  /** How many substantial paragraphs count as the opening. */
  opening?: number;
  /**
   * Fewer words a line than this, on average, is a block of lines (an
   * address, contact details), not prose.
   */
  minLineWords?: number;
}

/**
 * A block's prose, on one line; '' when it is a block of short lines — an
 * address reads "Headquarters / Springfield / 742 Evergreen Terrace", and run
 * together it is no sentence.
 */
function proseOf(text: string, minLineWords: number): string {
  const lines = text.split('\n').map((line) => line.replace(/\s+/g, ' ').trim()).filter(Boolean);
  if (lines.length > 1) {
    const words = lines.reduce((sum, line) => sum + wordsOf(line).length, 0);
    if (words / lines.length < minLineWords) {
      return '';
    }
  }
  return lines.join(' ');
}

/** Words of a text, lowercased, without diacritics or punctuation. */
function wordsOf(text: string): string[] {
  return text
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .split(/[^\p{L}\p{N}]+/u)
    .filter(Boolean);
}

/** Sentences of a paragraph, each with its closing punctuation. */
export function sentencesOf(text: string): string[] {
  const clean = text.replace(/\s+/g, ' ').trim();
  if (!clean) {
    return [];
  }
  // A sentence ends at . ! ? (and any closing quote or bracket) before a
  // space and a capital, digit or opening quote.
  const parts = clean.split(/(?<=[.!?]["'”’)\]]?)\s+(?=["'“‘(\[]?[\p{Lu}\p{N}])/u);
  return parts.map((part) => part.trim()).filter(Boolean);
}

/** Whether a sentence holds every word of the keyphrase. */
function holds(sentence: string, keyphrase: string): boolean {
  const wanted = wordsOf(keyphrase);
  if (!wanted.length) {
    return false;
  }
  const have = new Set(wordsOf(sentence));
  return wanted.every((word) => have.has(word));
}

/** Words a cut should not end on: "… comfort, wellness, and…" reads broken. */
const DANGLING = new Set(['a', 'an', 'and', 'as', 'at', 'but', 'by', 'for', 'from', 'in', 'into', 'of', 'on', 'or', 'so', 'than', 'that', 'the', 'to', 'with']);

/**
 * The longest run of whole words from the start of `text` that fits, with an
 * ellipsis and without a dangling connective; '' when not even one word does.
 */
function cutAtWord(text: string, fits: (candidate: string) => boolean): string {
  const words = text.split(' ');
  let count = 0;
  for (let i = 1; i <= words.length; i++) {
    if (!fits(words.slice(0, i).join(' ') + '…')) {
      break;
    }
    count = i;
  }
  while (count > 1 && DANGLING.has((words[count - 1] ?? '').toLowerCase().replace(/[^\p{L}]/gu, ''))) {
    count--;
  }
  return count ? words.slice(0, count).join(' ').replace(/[\s,;:–—-]+$/, '') + '…' : '';
}

/**
 * The suggestion, or null when the page has no usable paragraph.
 */
export function suggestDescription(blocks: SuggestBlock[], options: SuggestOptions): string | null {
  const limit = options.limit ?? 920;
  const minWords = options.minWords ?? 8;
  const minLineWords = options.minLineWords ?? 6;
  const fits = (text: string) => options.measure(text) <= limit;
  const paragraphs = blocks
    .filter((block) => (block.linkRatio ?? 0) < 0.5)
    .map((block) => proseOf(block.text, minLineWords))
    .filter((text) => wordsOf(text).length >= minWords)
    .slice(0, options.opening ?? 3);
  if (!paragraphs.length) {
    return null;
  }

  // Start at the first sentence holding the keyphrase, when one fits in the
  // opening; otherwise at the start of the first paragraph.
  let start = { paragraph: 0, sentence: 0 };
  if (options.keyphrase && options.keyphrase.trim()) {
    search:
    for (let p = 0; p < paragraphs.length; p++) {
      const sentences = sentencesOf(paragraphs[p] ?? '');
      for (let s = 0; s < sentences.length; s++) {
        const sentence = sentences[s] ?? '';
        if (holds(sentence, options.keyphrase) && fits(sentence)) {
          start = { paragraph: p, sentence: s };
          break search;
        }
      }
    }
  }

  // Whole sentences from there, as many as fit.
  const sentences = sentencesOf(paragraphs[start.paragraph] ?? '').slice(start.sentence);
  let text = '';
  for (const sentence of sentences) {
    const next = text ? `${text} ${sentence}` : sentence;
    if (!fits(next)) {
      break;
    }
    text = next;
  }
  // A first sentence too long to fit: as many whole words of it as do.
  if (!text) {
    text = cutAtWord(sentences[0] ?? '', fits);
  }
  return text || null;
}
