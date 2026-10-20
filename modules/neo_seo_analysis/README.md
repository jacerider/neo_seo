# Neo | SEO Analysis

Focus-keyphrase and readability analysis for the SEO panel. The engine is our own TypeScript, written
from Yoast's published descriptions of its free checks; no Yoast code is used.
Thresholds follow Yoast's published guidance and all live in `src/js/engine/thresholds.ts`.

## In the SEO panel

Enabling the module (it requires `neo_seo_panel`) adds analysis to both SEO panel hosts
(`src/js/analysis.ts`, one `Analyzer` behind two surfaces):

- **Edit form** — `Drupal.neoSeoPanel.extend()`, into the places the widget alter keeps for it
  (`data-neo-seo-section`): in the field's SEO section a `Scorecard` (score ring, status line, and
  the keyphrase and cornerstone controls, moved into it) above the SEO checks; in a Readability
  section it adds, the readability scorecard and checks. Every draft render
  is analysed. For a page built in its own Alchemist layout (`AlchemistLayout::customField()`:
  a component tree allowing a custom layout, shown by the full view mode) there is no Readability
  section; the SEO section shows both scores and a link to the layout editor
  (`AlchemistLayout::editorUrl()`, `?neo_seo=seo`, which opens the badge's SEO view), confirmed
  first while the form holds unsaved changes. neo_alchemist stays optional.
- **Alchemist** — the SEO panel's top bar badge (`hook_neo_seo_panel_badge_alter()` +
  `Drupal.neoSeoPanel.extendBadge()`): two pills, a small ring then `SEO 80` / `Read 64` (green
  70+, orange 40–69, red below; without a keyphrase the partial SEO score, and the pill opens the
  dropdown on the keyphrase field). They are scored from editor load off the editor's shared draft render, refreshed
  after every layout or SEO draft change whether or not the panel was opened, and dimmed with a
  spinner while that happens. The dropdown (520px, one view a pill) holds the same scorecard —
  the SEO one with the keyphrase and cornerstone controls, drafted through the panel's draft key
  like every SEO field — and checks as the form; *Show in preview* marks an assessment in the
  preview frames and keeps the dropdown open. The Alchemist panel itself
  carries no analysis.

A keyphrase or cornerstone change re-runs the last render without asking the server again. The two
scores are written back through the host quietly: hidden inputs saved with the form, or the panel's
draft key when a draft already exists. A score never blocks a save or a publish.

`GET /neo-seo/keyphrases?entity_type=…&exclude=…` (permission `edit neo_seo overrides`) lists the
keyphrases of other pages the editor may view, capped at 500 pages, for the "used before" check.

### Why the worker is inlined

The engine runs in a Web Worker, bundled with `import AnalysisWorker from
'./analysis.worker?worker&inline'`. The usual `new Worker(new URL('./worker.ts', import.meta.url))`
does not survive neo_build: chunks are served as classic scripts, where `import.meta` is a syntax
error, and the emitted worker file is not in the manifest (its URL comes out as `/assets/…`, outside
`dist/`). Inline, Vite bundles the worker as an IIFE into `analysis.js` and starts it from a Blob
URL, so nothing has to be resolved at runtime. There is no main-thread fallback: importing the
engine into a type-checked entrypoint would put its `.ts` import specifiers (which node's test
runner needs) in front of `tsc`, which rejects them (TS5097).

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
  structure:   { outline, headings, images },   // not scored
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
- **Structure** is reported beside the scores, never in them: `outline` lists every heading in
  order (`{level, text, issue?}`, issue `extraH1` or `skippedLevel`); `headings` holds `singleH1`
  (none or several H1s is a problem) and `headingLevels` (a skipped level is ok-band; not run with
  fewer than two headings); `images` holds `imageAltText` (missing, empty, or file-name alt text,
  each case counted and its files named in the message; not run without images).

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
  structure/      heading outline and image alt text (not scored)
  readability/    Flesch, structure, passive voice, flow
tests/js/         node:test suites
```
