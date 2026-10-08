/**
 * A small, forgiving HTML tokenizer.
 *
 * Built for Drupal's well-formed render output, but it never throws: stray
 * `<`, unterminated tags and unknown entities degrade to text. The content of
 * raw-text elements (script, style, noscript, …) and comments is dropped here,
 * so callers only ever see tags and decoded text.
 */

export interface OpenTag {
  type: 'open';
  name: string;
  attrs: Record<string, string>;
  selfClosing: boolean;
}

export interface CloseTag {
  type: 'close';
  name: string;
}

export interface TextToken {
  type: 'text';
  text: string;
}

export type HtmlToken = OpenTag | CloseTag | TextToken;

/** Elements whose content is not markup; it is skipped wholesale. */
const RAW_TEXT = new Set([
  'script', 'style', 'noscript', 'textarea', 'title', 'iframe', 'xmp', 'noembed', 'noframes',
]);

const OPEN_NAME = /<([a-zA-Z][a-zA-Z0-9:-]*)/y;
const CLOSE_TAG = /<\/([a-zA-Z][a-zA-Z0-9:-]*)[^>]*>?/y;
const SPACE = /\s/;
const ATTR_NAME_END = /[\s/>=]/;
const UNQUOTED_END = /[\s>]/;

export function tokenize(html: string): HtmlToken[] {
  const tokens: HtmlToken[] = [];
  const lower = html.toLowerCase();
  const n = html.length;
  let i = 0;

  const pushText = (raw: string): void => {
    if (raw !== '') {
      tokens.push({ type: 'text', text: decodeEntities(raw) });
    }
  };

  while (i < n) {
    const lt = html.indexOf('<', i);
    if (lt === -1) {
      pushText(html.slice(i));
      break;
    }
    pushText(html.slice(i, lt));
    const next = html.charAt(lt + 1);

    if (html.startsWith('<!--', lt)) {
      const end = html.indexOf('-->', lt + 4);
      i = end === -1 ? n : end + 3;
      continue;
    }
    if (html.startsWith('<![CDATA[', lt)) {
      const end = html.indexOf(']]>', lt + 9);
      i = end === -1 ? n : end + 3;
      continue;
    }
    if (next === '!' || next === '?') {
      const end = html.indexOf('>', lt);
      i = end === -1 ? n : end + 1;
      continue;
    }

    if (next === '/') {
      CLOSE_TAG.lastIndex = lt;
      const m = CLOSE_TAG.exec(html);
      if (m === null) {
        pushText('<');
        i = lt + 1;
        continue;
      }
      tokens.push({ type: 'close', name: (m[1] ?? '').toLowerCase() });
      i = CLOSE_TAG.lastIndex;
      continue;
    }

    OPEN_NAME.lastIndex = lt;
    const m = OPEN_NAME.exec(html);
    if (m === null) {
      pushText('<');
      i = lt + 1;
      continue;
    }
    const name = (m[1] ?? '').toLowerCase();
    const parsed = parseAttributes(html, OPEN_NAME.lastIndex);
    i = parsed.end;

    if (RAW_TEXT.has(name) && !parsed.selfClosing) {
      const close = lower.indexOf('</' + name, i);
      if (close === -1) {
        i = n;
      }
      else {
        const gt = html.indexOf('>', close);
        i = gt === -1 ? n : gt + 1;
      }
      continue;
    }
    tokens.push({ type: 'open', name, attrs: parsed.attrs, selfClosing: parsed.selfClosing });
  }
  return tokens;
}

function parseAttributes(
  html: string,
  start: number,
): { attrs: Record<string, string>; selfClosing: boolean; end: number } {
  const attrs: Record<string, string> = {};
  const n = html.length;
  let selfClosing = false;
  let i = start;

  while (i < n) {
    const c = html.charAt(i);
    if (c === '>') {
      i++;
      break;
    }
    if (c === '/') {
      if (html.charAt(i + 1) === '>') {
        selfClosing = true;
        i += 2;
        break;
      }
      i++;
      continue;
    }
    if (SPACE.test(c) || c === '=') {
      i++;
      continue;
    }

    let j = i;
    while (j < n && !ATTR_NAME_END.test(html.charAt(j))) {
      j++;
    }
    const name = html.slice(i, j).toLowerCase();
    i = j;
    while (i < n && SPACE.test(html.charAt(i))) {
      i++;
    }

    let value = '';
    if (html.charAt(i) === '=') {
      i++;
      while (i < n && SPACE.test(html.charAt(i))) {
        i++;
      }
      const q = html.charAt(i);
      if (q === '"' || q === "'") {
        const end = html.indexOf(q, i + 1);
        value = html.slice(i + 1, end === -1 ? n : end);
        i = end === -1 ? n : end + 1;
      }
      else {
        let k = i;
        while (k < n && !UNQUOTED_END.test(html.charAt(k))) {
          k++;
        }
        value = html.slice(i, k);
        i = k;
      }
    }
    if (!Object.hasOwn(attrs, name)) {
      attrs[name] = decodeEntities(value);
    }
  }
  return { attrs, selfClosing, end: i };
}

const NAMED_ENTITIES: Record<string, string> = {
  amp: '&', lt: '<', gt: '>', quot: '"', apos: "'", nbsp: ' ', shy: '­',
  ndash: '–', mdash: '—', hellip: '…', lsquo: '‘', rsquo: '’',
  sbquo: '‚', ldquo: '“', rdquo: '”', bdquo: '„', laquo: '«',
  raquo: '»', lsaquo: '‹', rsaquo: '›', bull: '•', middot: '·',
  copy: '©', reg: '®', trade: '™', deg: '°', times: '×',
  divide: '÷', euro: '€', pound: '£', yen: '¥', cent: '¢',
  sect: '§', para: '¶', iexcl: '¡', iquest: '¿', szlig: 'ß',
  aelig: 'æ', AElig: 'Æ', oelig: 'œ', OElig: 'Œ', oslash: 'ø',
  Oslash: 'Ø', eth: 'ð', ETH: 'Ð', thorn: 'þ', THORN: 'Þ',
  ensp: ' ', emsp: ' ', thinsp: ' ', zwnj: '‌', zwj: '‍',
};

// Accented Latin letters (&eacute; &Uuml; &ccedil; …), generated rather than listed.
const MARKS: Record<string, string> = {
  grave: '̀', acute: '́', circ: '̂', tilde: '̃', uml: '̈',
  ring: '̊', cedil: '̧',
};
for (const letter of 'aeiouyncAEIOUYNC') {
  for (const [mark, combining] of Object.entries(MARKS)) {
    const composed = (letter + combining).normalize('NFC');
    if (composed.length === 1) {
      NAMED_ENTITIES[letter + mark] = composed;
    }
  }
}

/** Entities that browsers decode even without the trailing semicolon. */
const LEGACY_NO_SEMICOLON = new Set(['amp', 'lt', 'gt', 'quot', 'nbsp']);

const ENTITY = /&(#[0-9]+|#[xX][0-9a-fA-F]+|[a-zA-Z][a-zA-Z0-9]*)(;?)/g;

export function decodeEntities(text: string): string {
  if (!text.includes('&')) {
    return text;
  }
  return text.replace(ENTITY, (whole: string, body: string, semi: string): string => {
    if (body.charAt(0) === '#') {
      const hex = body.charAt(1) === 'x' || body.charAt(1) === 'X';
      const code = Number.parseInt(body.slice(hex ? 2 : 1), hex ? 16 : 10);
      if (!Number.isFinite(code) || code <= 0 || code > 0x10ffff || (code >= 0xd800 && code <= 0xdfff)) {
        return '�';
      }
      return String.fromCodePoint(code);
    }
    const named = NAMED_ENTITIES[body];
    if (named === undefined || (semi === '' && !LEGACY_NO_SEMICOLON.has(body))) {
      return whole;
    }
    return named;
  });
}
