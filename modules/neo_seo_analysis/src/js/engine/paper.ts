/**
 * HTML → document model ("paper").
 *
 * The paper is what every assessment reads: the text as a sequence of blocks
 * (headings and paragraph-like blocks, in document order), plus the images
 * and links found anywhere in the body.
 */

import { tokenize } from './html.ts';

export interface Heading {
  kind: 'heading';
  level: number;
  text: string;
}

export interface Paragraph {
  kind: 'paragraph';
  /** The innermost block element the text came from: 'p', 'li', 'td', 'div' … */
  tag: string;
  text: string;
}

export type Block = Heading | Paragraph;

export interface Image {
  src: string;
  /** null when the alt attribute is missing entirely. */
  alt: string | null;
}

export interface Link {
  href: string;
  rel: string;
  text: string;
}

export interface Paper {
  blocks: Block[];
  headings: Heading[];
  paragraphs: Paragraph[];
  /** All block text, blocks separated by a blank line. */
  text: string;
  /** The first <p> block, or the first paragraph-like block when there is none. */
  introduction: string;
  images: Image[];
  links: Link[];
}

/** Elements that start and end a block of text. */
const BLOCK_TAGS = new Set([
  'address', 'article', 'aside', 'blockquote', 'body', 'caption', 'dd', 'details', 'dialog',
  'div', 'dl', 'dt', 'fieldset', 'figcaption', 'figure', 'footer', 'form', 'header', 'hgroup',
  'hr', 'html', 'legend', 'li', 'main', 'menu', 'nav', 'ol', 'p', 'pre', 'section', 'summary',
  'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr', 'ul', 'button', 'label',
]);

/** Elements whose whole subtree is ignored (no visible prose). */
const SKIP_TAGS = new Set(['template', 'svg', 'math', 'head', 'select', 'object', 'video', 'audio', 'canvas']);

/** Void elements: never have a closing tag, never pushed on the stack. */
const VOID_TAGS = new Set([
  'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param',
  'source', 'track', 'wbr',
]);

const HEADING = /^h([1-6])$/;

export function cleanText(text: string): string {
  return text.replace(/­/g, '').replace(/\s+/g, ' ').trim();
}

export function buildPaper(html: string): Paper {
  const blocks: Block[] = [];
  const images: Image[] = [];
  const links: Link[] = [];
  const stack: string[] = [];
  let buffer = '';
  let headingLevel = 0;
  let link: Link | null = null;
  let skipName = '';
  let skipDepth = 0;

  const flush = (): void => {
    const text = cleanText(buffer);
    buffer = '';
    if (headingLevel > 0) {
      if (text !== '') {
        blocks.push({ kind: 'heading', level: headingLevel, text });
      }
      headingLevel = 0;
      return;
    }
    if (text !== '') {
      blocks.push({ kind: 'paragraph', tag: stack[stack.length - 1] ?? 'body', text });
    }
  };

  const closeLink = (): void => {
    if (link !== null) {
      link.text = cleanText(link.text);
      links.push(link);
      link = null;
    }
  };

  for (const token of tokenize(html)) {
    if (skipDepth > 0) {
      if (token.type === 'open' && token.name === skipName && !token.selfClosing) {
        skipDepth++;
      }
      else if (token.type === 'close' && token.name === skipName) {
        skipDepth--;
      }
      continue;
    }

    if (token.type === 'text') {
      buffer += token.text;
      if (link !== null) {
        link.text += token.text;
      }
      continue;
    }

    const name = token.name;
    const heading = HEADING.exec(name);

    if (token.type === 'open') {
      if (SKIP_TAGS.has(name)) {
        if (!token.selfClosing) {
          skipName = name;
          skipDepth = 1;
        }
        continue;
      }
      if (name === 'img') {
        images.push({
          src: token.attrs['src'] ?? '',
          alt: Object.hasOwn(token.attrs, 'alt') ? cleanText(token.attrs['alt'] ?? '') : null,
        });
        continue;
      }
      if (name === 'br') {
        buffer += ' ';
        if (link !== null) {
          link.text += ' ';
        }
        continue;
      }
      if (name === 'a') {
        closeLink();
        link = { href: (token.attrs['href'] ?? '').trim(), rel: (token.attrs['rel'] ?? '').toLowerCase(), text: '' };
        continue;
      }
      if (heading !== null) {
        flush();
        headingLevel = Number(heading[1]);
        continue;
      }
      if (BLOCK_TAGS.has(name)) {
        // Inside a heading a stray block wrapper must not cut the heading short.
        if (headingLevel === 0) {
          flush();
        }
        if (!VOID_TAGS.has(name) && !token.selfClosing) {
          stack.push(name);
        }
      }
      continue;
    }

    // Closing tags.
    if (name === 'a') {
      closeLink();
      continue;
    }
    if (heading !== null) {
      if (headingLevel > 0) {
        flush();
      }
      continue;
    }
    if (BLOCK_TAGS.has(name)) {
      if (headingLevel === 0) {
        flush();
      }
      const at = stack.lastIndexOf(name);
      if (at !== -1) {
        stack.length = at;
      }
    }
  }
  flush();
  closeLink();

  const headings = blocks.filter((b): b is Heading => b.kind === 'heading');
  const paragraphs = blocks.filter((b): b is Paragraph => b.kind === 'paragraph');
  const intro = paragraphs.find((p) => p.tag === 'p') ?? paragraphs[0];

  return {
    blocks,
    headings,
    paragraphs,
    text: blocks.map((b) => b.text).join('\n\n'),
    introduction: intro?.text ?? '',
    images,
    links,
  };
}
