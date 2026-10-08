# Neo | SEO Analysis

Focus-keyphrase and readability analysis for the SEO panel. The engine is our own TypeScript, written
from Yoast's published descriptions of its free checks; no Yoast code is used (ADR 0003 in the wps
site). Thresholds follow Yoast's published guidance and all live in `src/js/engine/thresholds.ts`.

Drupal wiring (worker, endpoint, panel) is ticket 17; this module currently ships only the engine.

## Tests

From the neo_seo package root, with Node 24 (runs `.ts` directly, no npm install):

```bash
node --test 'modules/neo_seo_analysis/tests/js/*.test.ts'
```

Node 24 does not accept a bare directory for `--test`; pass the quoted glob.

## Contract

`src/js/engine/index.ts` exports one pure function. No DOM, no `window`, no Drupal: it runs in a
Web Worker and in node, and its result is plain data.

```ts
analyze({
  html,            // rendered page body HTML (the draft render)
  title,           // resolved SEO title
  description,     // resolved meta description
  url,             // absolute or path URL; the slug is its last path segment
  keyphrase,       // '' → one "keyphraseMissing" problem, keyphrase checks skipped
  cornerstone,     // stricter thresholds, nothing else
  language,        // 'en', 'en-US', 'fr' …
  siteHost?,       // tells internal from outbound links; else the host of an absolute url
  usedKeyphrases?, // keyphrases of OTHER pages; omitted → that check does not run
}) → {
  seo:         { score, assessments },
  readability: { available, score, assessments, message? },
}

Assessment = { id, band: 'good' | 'ok' | 'problem', message, highlights? }
Highlight  = { kind: 'sentence' | 'paragraph', text }
           | { kind: 'heading', level, text }
           | { kind: 'image', src, alt }
           | { kind: 'link', href, text }
```

- **Scores** are 0–100: each assessment scores good 100, ok 50, problem 0, and the score is the
  rounded mean of the assessments that ran.
- **Not applicable** checks are left out rather than scored: keyphrase in slug (no slug, e.g. the
  front page), in H1 (no H1 in the body), in subheadings (no H2/H3), used before (no list), and
  transition words (under 200 words).
- **Readability** is English only. For any other language `available` is `false`, `assessments` is
  empty and `message` says why.
- **Matching** is case- and diacritic-insensitive and whole-word, with no stemming. "All keyphrase
  words in any order" ignores English function words; title position uses the exact phrase.
- **Messages** are English strings.

## Layout

```
src/js/engine/
  index.ts        analyze()
  types.ts        public types
  html.ts         forgiving HTML tokenizer + entity decoding
  paper.ts        HTML → blocks, headings, paragraphs, introduction, images, links
  text.ts         words, sentences, syllables, keyphrase matching
  english.ts      English word lists
  thresholds.ts   every threshold, regular and cornerstone
  score.ts        overall score
  context.ts      per-run shared state
  seo/            keyphrase, length and link assessments
  readability/    Flesch, structure, passive voice, flow
tests/js/         node:test suites
```
