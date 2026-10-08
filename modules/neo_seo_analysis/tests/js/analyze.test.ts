import assert from 'node:assert/strict';
import { describe, test } from 'node:test';
import { analyze } from '../../src/js/engine/index.ts';
import type { AnalysisInput } from '../../src/js/engine/index.ts';
import { filler, p, run } from './helpers.ts';

/** Markup shaped like a Drupal full view-mode render. */
const DRUPAL_PAGE = `
<article data-history-node-id="12" class="node node--type-page node--view-mode-full">
  <!-- THEME DEBUG -->
  <div class="eyebrow">Recipes</div>
  <h1 class="page-title"><span>Sourdough bread at home</span></h1>
  <div class="field field--name-body">
    <p>Baking sourdough bread at home takes time, but it&rsquo;s worth it.
       <a href="/recipes/starter">Make a starter</a> first, or read
       <a href="https://en.wikipedia.org/wiki/Sourdough" rel="noopener">the history</a>.</p>
    <h2>What you need</h2>
    <ul><li>Flour<li>Water<li>Salt</ul>
    <figure><img src="/files/loaf.jpg" alt="A sourdough bread loaf" loading="lazy" />
      <figcaption>Fresh from the oven.</figcaption></figure>
    <h2>Method</h2>
    <p>${filler(300)}</p>
  </div>
  <script type="application/json" data-drupal-selector="drupal-settings-json">{"path":{"baseUrl":"\\/"}}</script>
  <noscript><img src="/files/loaf.jpg" alt=""></noscript>
</article>`;

const INPUT: AnalysisInput = {
  html: DRUPAL_PAGE,
  title: 'Sourdough bread at home: a complete beginner guide',
  description: 'Learn to bake sourdough bread at home with this complete, step-by-step guide for beginners. '
    + 'From starter to loaf, every step is explained.',
  url: 'https://example.com/recipes/sourdough-bread',
  keyphrase: 'sourdough bread',
  cornerstone: false,
  language: 'en-US',
  siteHost: 'example.com',
  usedKeyphrases: ['banana bread'],
};

describe('analyze()', () => {
  test('returns the documented shape for a realistic page', () => {
    const result = analyze(INPUT);
    assert.equal(typeof result.seo.score, 'number');
    assert.ok(result.seo.score >= 0 && result.seo.score <= 100);
    assert.equal(result.readability.available, true);
    for (const a of [...result.seo.assessments, ...result.readability.assessments]) {
      assert.equal(typeof a.id, 'string');
      assert.ok(['good', 'ok', 'problem'].includes(a.band), a.id);
      assert.ok(a.message.length > 0, a.id);
    }
    const ids = result.seo.assessments.map((a) => a.id);
    assert.deepEqual(ids, [
      'keyphraseLength', 'keyphraseInTitle', 'keyphraseInDescription', 'keyphraseInSlug',
      'keyphraseInH1', 'keyphraseInIntroduction', 'keyphraseInSubheadings', 'keyphraseInImageAlt',
      'keyphraseDensity', 'previouslyUsedKeyphrase', 'titleWidth', 'metaDescriptionLength',
      'textLength', 'internalLinks', 'outboundLinks',
    ]);
    assert.deepEqual(result.readability.assessments.map((a) => a.id), [
      'fleschReadingEase', 'sentenceLength', 'paragraphLength', 'subheadingDistribution',
      'passiveVoice', 'transitionWords', 'sentenceBeginnings',
    ]);
  });

  test('reads the realistic page correctly', () => {
    const bands = Object.fromEntries(analyze(INPUT).seo.assessments.map((a) => [a.id, a.band]));
    assert.equal(bands['keyphraseInH1'], 'good');
    assert.equal(bands['keyphraseInIntroduction'], 'good');
    assert.equal(bands['keyphraseInImageAlt'], 'good'); // the noscript copy is ignored
    assert.equal(bands['internalLinks'], 'good');
    assert.equal(bands['outboundLinks'], 'good');
    assert.equal(bands['keyphraseInSubheadings'], 'ok'); // 0 of 2
    assert.equal(bands['previouslyUsedKeyphrase'], 'good');
  });

  test('results are plain data (survive structuredClone and JSON)', () => {
    const result = analyze(INPUT);
    assert.deepEqual(structuredClone(result), result);
    assert.deepEqual(JSON.parse(JSON.stringify(result)), result);
  });

  test('is deterministic and does not mutate its input', () => {
    const copy = structuredClone(INPUT);
    assert.deepEqual(analyze(INPUT), analyze(INPUT));
    assert.deepEqual(INPUT, copy);
  });

  test('tolerates missing or malformed fields from an untyped caller', () => {
    const loose = { html: null, title: undefined, keyphrase: 42, usedKeyphrases: ['a', null] } as unknown as AnalysisInput;
    assert.doesNotThrow(() => analyze(loose));
    assert.equal(analyze(loose).readability.available, false);
  });

  test('tolerates broken markup', () => {
    for (const html of ['<p>Open <b>never <i>closed', '</div></p><p>Stray closers', '<<>><img <a href=>', '<p>a &# &#x; &#99999999;']) {
      assert.doesNotThrow(() => run({ html, keyphrase: 'a' }), html);
    }
  });

  test('handles a long page quickly', () => {
    const html = Array.from({ length: 200 }, (_, i) => `<h2>Part ${i}</h2>` + p(filler(100, 12, i))).join('');
    const started = performance.now();
    run({ html, keyphrase: 'red fox' });
    assert.ok(performance.now() - started < 1000, 'a 20,000-word page analyses in under a second');
  });
});
