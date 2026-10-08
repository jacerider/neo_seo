/**
 * Pixel widths, the way Google measures a search result.
 *
 * Google cuts a desktop result by rendered width, not by characters: about
 * 600px of title at 20px Arial and about 920px of description (two lines) at
 * 14px Arial. A canvas measures the same font the same way, so the panel's cut
 * lands within a character or two of Google's.
 */

export const TITLE_FONT = '20px Arial, sans-serif';
export const DESCRIPTION_FONT = '14px Arial, sans-serif';
export const TITLE_LIMIT = 600;
export const DESCRIPTION_LIMIT = 920;

const ELLIPSIS = ' ...';

let context: CanvasRenderingContext2D | null | undefined;

export function width(text: string, font: string): number {
  if (context === undefined) {
    context = document.createElement('canvas').getContext('2d');
  }
  if (!context) {
    // No canvas: an average Arial glyph is a little over half the font size.
    return text.length * parseFloat(font) * 0.55;
  }
  context.font = font;
  return context.measureText(text).width;
}

export interface Cut {
  text: string;
  truncated: boolean;
  /** The full text's width. */
  width: number;
}

/**
 * The text as Google would show it within `limit` pixels: whole if it fits,
 * otherwise cut at a word boundary with " ..." appended.
 */
export function cut(text: string, font: string, limit: number): Cut {
  const full = width(text, font);
  if (full <= limit) {
    return { text, truncated: false, width: full };
  }
  // The longest prefix that fits with the ellipsis.
  let low = 0;
  let high = text.length;
  while (low < high) {
    const mid = Math.ceil((low + high) / 2);
    if (width(text.slice(0, mid).trimEnd() + ELLIPSIS, font) <= limit) {
      low = mid;
    }
    else {
      high = mid - 1;
    }
  }
  let prefix = text.slice(0, low);
  // Prefer a word boundary, unless that throws most of the line away.
  const space = prefix.lastIndexOf(' ');
  if (space > low * 0.6 && !/\s/.test(text.charAt(low))) {
    prefix = prefix.slice(0, space);
  }
  return { text: prefix.trimEnd().replace(/[\s,;:.\-–—|]+$/, '') + ELLIPSIS, truncated: true, width: full };
}
