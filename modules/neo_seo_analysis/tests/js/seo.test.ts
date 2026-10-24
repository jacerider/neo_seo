import assert from 'node:assert/strict';
import { describe, test } from 'node:test';
import { classifyLink, siteHostOf } from '../../src/js/engine/seo/links.ts';
import { slugWords } from '../../src/js/engine/seo/keyphrase.ts';
import { band, chars, filler, p, run, seo } from './helpers.ts';

const KEYPHRASE_IDS = [
  'keyphraseLength', 'keyphraseInTitle', 'keyphraseInDescription', 'keyphraseInSlug',
  'keyphraseInH1', 'keyphraseInIntroduction', 'keyphraseInSubheadings', 'keyphraseInImageAlt',
  'keyphraseDensity', 'previouslyUsedKeyphrase',
];

describe('no keyphrase set', () => {
  test('reports a problem and skips every keyphrase check', () => {
    for (const keyphrase of ['', '   ', '""']) {
      const ids = run({ keyphrase, html: p('Bread.') }).seo.assessments.map((a) => a.id);
      assert.ok(ids.includes('keyphraseMissing'), keyphrase);
      assert.equal(band(seo({ keyphrase }, 'keyphraseMissing')), 'problem');
      for (const id of KEYPHRASE_IDS) {
        assert.ok(!ids.includes(id), `${id} should not run without a keyphrase`);
      }
    }
  });

  test('the checks that need no keyphrase still run', () => {
    const ids = run({ keyphrase: '' }).seo.assessments.map((a) => a.id);
    assert.deepEqual(ids, ['keyphraseMissing', 'titleWidth', 'metaDescriptionLength', 'textLength', 'internalLinks', 'outboundLinks']);
  });
});

describe('keyphraseLength', () => {
  const at = (keyphrase: string, language = 'en'): string => band(seo({ keyphrase, language }, 'keyphraseLength'));

  test('English counts content words: ≤4 good, 5–8 ok, 9+ problem', () => {
    assert.equal(at('bread'), 'good');
    assert.equal(at('how to bake sourdough bread at home'), 'good'); // bake sourdough bread home
    assert.equal(at('bake sourdough rye bread loaves'), 'ok');
    assert.equal(at('ax bx cx dx ex fx gx hx'), 'ok');
    assert.equal(at('ax bx cx dx ex fx gx hx ix'), 'problem');
  });

  test('other languages count every word: ≤6 good, 7–9 ok, 10+ problem', () => {
    assert.equal(at('le pain de mie au levain', 'fr'), 'good');
    assert.equal(at('le pain de mie au levain naturel', 'fr'), 'ok');
    assert.equal(at('a b c d e f g h i', 'fr'), 'ok');
    assert.equal(at('a b c d e f g h i j', 'fr'), 'problem');
  });
});

describe('keyphraseInTitle', () => {
  const at = (title: string, keyphrase = 'sourdough bread'): string => band(seo({ keyphrase, title }, 'keyphraseInTitle'));

  test('exact phrase at the start is good, also after leading function words', () => {
    assert.equal(at('Sourdough bread for beginners'), 'good');
    assert.equal(at('How to make sourdough bread', 'make sourdough bread'), 'good');
    assert.equal(at('The sourdough bread guide'), 'good');
  });

  test('exact phrase later is ok; all words in another order is ok', () => {
    assert.equal(at('A beginner guide to sourdough bread'), 'ok');
    assert.equal(at('Bread made with sourdough'), 'ok');
  });

  test('missing or partial is a problem; an empty title is a problem', () => {
    assert.equal(at('Sourdough starters explained'), 'problem');
    assert.equal(at(''), 'problem');
  });

  test('matching ignores case and diacritics but needs whole words', () => {
    assert.equal(at('CAFÉ LATTE at home', 'cafe latte'), 'good');
    assert.equal(at('Sourdough breads', 'sourdough bread'), 'problem');
    assert.equal(at('Sourdoughbread', 'sourdough bread'), 'problem');
  });
});

describe('keyphraseInDescription', () => {
  const at = (description: string): string => band(seo({ keyphrase: 'sourdough bread', description }, 'keyphraseInDescription'));

  test('0 problem, 1–2 good, 3+ ok, empty problem', () => {
    assert.equal(at('Learn to bake at home.'), 'problem');
    assert.equal(at('Bake sourdough bread at home.'), 'good');
    assert.equal(at('Sourdough bread is easy. Bread with sourdough tastes better.'), 'good');
    assert.equal(at('Sourdough bread. Sourdough bread. Sourdough bread.'), 'ok');
    assert.equal(at(''), 'problem');
  });
});

describe('keyphraseInSlug', () => {
  const at = (url: string, keyphrase = 'sourdough bread recipe', cornerstone = false): string | undefined =>
    seo({ keyphrase, url, cornerstone }, 'keyphraseInSlug')?.band;

  test('the keyphrase leading the slug is good (hyphens and underscores are spaces)', () => {
    assert.equal(at('https://example.com/blog/sourdough-bread-recipe'), 'good');
    assert.equal(at('/sourdough_bread_recipe-for-beginners?page=2#top'), 'good');
    assert.equal(at('/caf%C3%A9-latte', 'café latte'), 'good');
  });

  test('all words but not at the front is ok, with how to fix it', () => {
    assert.equal(at('/recipe_sourdough_bread'), 'ok');
    assert.equal(at('/easy-sourdough-bread-recipe'), 'ok');
    const result = seo({ keyphrase: 'sourdough bread recipe', url: '/easy-sourdough-bread-recipe' }, 'keyphraseInSlug');
    assert.match(result?.message ?? '', /Move it to the front/);
  });

  test('function words dropped from the slug still count as the front', () => {
    assert.equal(at('/how-bake-sourdough-bread', 'how to bake sourdough bread'), 'good');
    assert.equal(at('/the-sourdough-bread-recipe'), 'good');
  });

  test('more than half ok (problem for cornerstone); half or less problem', () => {
    assert.equal(at('/sourdough-bread'), 'ok');
    assert.equal(at('/sourdough-bread', undefined, true), 'problem');
    assert.equal(at('/sourdough', 'sourdough bread'), 'problem');
    assert.equal(at('/sourdough-cake'), 'problem');
  });

  test('hyphenated keyphrase words match a slug', () => {
    assert.equal(at('/well-known-brands', 'well-known brands'), 'good');
  });

  test('does not run when there is no slug (front page)', () => {
    assert.equal(at('https://example.com/'), undefined);
    assert.equal(at('/'), undefined);
    assert.equal(at(''), undefined);
  });

  test('slugWords takes only the last path segment', () => {
    assert.deepEqual(slugWords('https://example.com/blog/my-post/'), ['my', 'post']);
    assert.deepEqual(slugWords('//cdn.example.com/a/b_c'), ['b', 'c']);
  });
});

describe('keyphraseInH1', () => {
  const at = (html: string): string | undefined => seo({ keyphrase: 'sourdough bread', html }, 'keyphraseInH1')?.band;

  test('good when an H1 contains the keyphrase, problem when none does', () => {
    assert.equal(at('<h1>Bread: the sourdough way</h1>'), 'good');
    assert.equal(at('<h1>Baking at home</h1>'), 'problem');
    assert.equal(at('<h1>Baking</h1><h1>Sourdough bread</h1>'), 'good');
  });

  test('does not run when the rendered body has no H1', () => {
    assert.equal(at('<h2>Sourdough bread</h2><p>Text.</p>'), undefined);
  });

  test('highlights the H1 that misses', () => {
    assert.deepEqual(seo({ keyphrase: 'bread', html: '<h1>Cake</h1>' }, 'keyphraseInH1')?.highlights, [{ kind: 'heading', level: 1, text: 'Cake' }]);
  });
});

describe('keyphraseInIntroduction', () => {
  const at = (html: string): string => band(seo({ keyphrase: 'sourdough bread', html }, 'keyphraseInIntroduction'));

  test('all words in one sentence good; across sentences ok; absent problem', () => {
    assert.equal(at(p('This bread uses a sourdough starter. More text.')), 'good');
    assert.equal(at(p('We love sourdough. Bread is great.')), 'ok');
    assert.equal(at(p('We love cake.') + p('Sourdough bread later.')), 'problem');
  });

  test('uses the first <p>, not an eyebrow div or a heading', () => {
    assert.equal(at('<div>Sourdough bread</div><h2>Sourdough bread</h2>' + p('Nothing here.')), 'problem');
    assert.equal(at('<div>Recipes</div>' + p('Sourdough bread for all.')), 'good');
  });

  test('no paragraphs at all is a problem', () => {
    assert.equal(at('<h2>Sourdough bread</h2>'), 'problem');
  });
});

describe('keyphraseInSubheadings', () => {
  const html = (matching: number, total: number): string => {
    let out = '';
    for (let i = 0; i < total; i++) {
      out += i < matching ? `<h${2 + (i % 2)}>Sourdough bread part ${i}</h${2 + (i % 2)}>` : `<h2>Other part ${i}</h2>`;
    }
    return out;
  };
  const at = (matching: number, total: number): string | undefined =>
    seo({ keyphrase: 'sourdough bread', html: html(matching, total) }, 'keyphraseInSubheadings')?.band;

  test('share of H2/H3: <30% ok, 30–75% good, >75% problem', () => {
    assert.equal(at(0, 4), 'ok');
    assert.equal(at(1, 4), 'ok'); // 25%
    assert.equal(at(3, 10), 'good'); // 30%
    assert.equal(at(3, 4), 'good'); // 75%
    assert.equal(at(4, 5), 'problem'); // 80%
  });

  test('a single subheading that contains it is good', () => {
    assert.equal(at(1, 1), 'good');
    assert.equal(at(0, 1), 'ok');
  });

  test('only H2 and H3 count; no subheadings means it does not run', () => {
    assert.equal(seo({ keyphrase: 'bread', html: '<h4>Bread</h4><h1>Bread</h1>' }, 'keyphraseInSubheadings'), undefined);
  });
});

describe('keyphraseInImageAlt', () => {
  const images = (matching: number, total: number, extra = ''): string => {
    let out = extra;
    for (let i = 0; i < total; i++) {
      out += i < matching ? `<img src="${i}.jpg" alt="Sourdough bread loaf">` : `<img src="${i}.jpg" alt="A kitchen">`;
    }
    return out;
  };
  const at = (matching: number, total: number): string => band(seo({ keyphrase: 'sourdough bread', html: images(matching, total) }, 'keyphraseInImageAlt'));

  test('no images is a problem', () => {
    assert.equal(at(0, 0), 'problem');
  });

  test('under 5 images: any matching alt good, none problem', () => {
    assert.equal(at(1, 4), 'good');
    assert.equal(at(0, 4), 'problem');
  });

  test('5+ images: <30% ok, 30–70% good, >70% ok, none problem', () => {
    assert.equal(at(0, 10), 'problem');
    assert.equal(at(2, 10), 'ok');
    assert.equal(at(3, 10), 'good');
    assert.equal(at(7, 10), 'good');
    assert.equal(at(8, 10), 'ok');
  });

  test('highlights images that miss, including ones without alt', () => {
    const found = seo({ keyphrase: 'bread', html: '<img src="a.jpg"><img src="b.jpg" alt="Cake">' }, 'keyphraseInImageAlt');
    assert.deepEqual(found?.highlights, [
      { kind: 'image', src: 'a.jpg', alt: null },
      { kind: 'image', src: 'b.jpg', alt: 'Cake' },
    ]);
  });
});

describe('keyphraseDensity', () => {
  /** Exactly `total` words, `hits` of which are one-word "Bread." sentences. */
  const text = (hits: number, total: number, sentence = 'Bread.'): string =>
    p(filler(total - hits * sentence.split(' ').length) + ' ' + Array(hits).fill(sentence).join(' '));
  const at = (hits: number, total = 1000, keyphrase = 'bread', sentence = 'Bread.'): string =>
    band(seo({ keyphrase, html: text(hits, total, sentence) }, 'keyphraseDensity'));

  test('one-word keyphrase: 0 problem, <0.5% ok, 0.5–3% good, ≤3.5% ok, more problem', () => {
    assert.equal(at(0), 'problem');
    assert.equal(at(4), 'ok'); // 0.4%
    assert.equal(at(5), 'good'); // 0.5%
    assert.equal(at(30), 'good'); // 3.0%
    assert.equal(at(31), 'ok'); // 3.1%
    assert.equal(at(35), 'ok'); // 3.5%
    assert.equal(at(36), 'problem'); // 3.6%
  });

  test('the maximum shrinks for long keyphrases (4 words: 1.5%, ok to 1.75%)', () => {
    const kp = 'fresh sourdough rye bread';
    const s = 'Fresh sourdough rye bread.';
    assert.equal(at(15, 1000, kp, s), 'good'); // 1.5%
    assert.equal(at(17, 1000, kp, s), 'ok'); // 1.7%
    assert.equal(at(18, 1000, kp, s), 'problem'); // 1.8%
  });

  test('the maximum never drops below 1% (eight-word keyphrase)', () => {
    const kp = 'ax bx cx dx ex fx gx hx';
    const s = 'Ax bx cx dx ex fx gx hx.';
    assert.equal(at(10, 1000, kp, s), 'good'); // 1.0%
    assert.equal(at(11, 1000, kp, s), 'ok');
  });

  test('a multi-word keyphrase counts only sentences that hold every word', () => {
    const html = p(filler(96) + ' Sourdough only. Bread only.');
    assert.equal(band(seo({ keyphrase: 'sourdough bread', html }, 'keyphraseDensity')), 'problem');
  });

  test('highlights the sentences that use it', () => {
    const found = seo({ keyphrase: 'bread', html: p('Bread is good. Cake is not.') }, 'keyphraseDensity');
    assert.deepEqual(found?.highlights, [{ kind: 'sentence', text: 'Bread is good.' }]);
  });
});

describe('previouslyUsedKeyphrase', () => {
  const at = (usedKeyphrases?: string[]): string | undefined => {
    const input = usedKeyphrases === undefined ? { keyphrase: 'Café latte' } : { keyphrase: 'Café latte', usedKeyphrases };
    return seo(input, 'previouslyUsedKeyphrase')?.band;
  };

  test('does not run without a list from the server', () => {
    assert.equal(at(undefined), undefined);
  });

  test('0 uses good, 1 ok, 2+ problem; comparison is normalized', () => {
    assert.equal(at([]), 'good');
    assert.equal(at(['cafe', 'latte art']), 'good');
    assert.equal(at(['CAFE LATTE']), 'ok');
    assert.equal(at(['cafe latte', ' Café  Latte ']), 'problem');
  });
});

describe('titleWidth', () => {
  const at = (n: number): string => band(seo({ title: chars(n) }, 'titleWidth'));

  test('0 problem, <40 ok, 40–60 good, >60 problem', () => {
    assert.equal(at(0), 'problem');
    assert.equal(at(39), 'ok');
    assert.equal(at(40), 'good');
    assert.equal(at(60), 'good');
    assert.equal(at(61), 'problem');
  });

  test('counts characters, not UTF-16 units', () => {
    assert.equal(band(seo({ title: '\u{1F600}'.repeat(40) }, 'titleWidth')), 'good');
  });
});

describe('metaDescriptionLength', () => {
  const at = (n: number): string => band(seo({ description: chars(n) }, 'metaDescriptionLength'));

  test('0 problem, <120 ok, 120–156 good, >156 ok', () => {
    assert.equal(at(0), 'problem');
    assert.equal(at(119), 'ok');
    assert.equal(at(120), 'good');
    assert.equal(at(156), 'good');
    assert.equal(at(157), 'ok');
  });
});

describe('textLength', () => {
  const at = (n: number, cornerstone = false): string => band(seo({ html: p(filler(n)), cornerstone }, 'textLength'));

  test('regular: <250 problem, 250–299 ok, 300+ good', () => {
    assert.equal(at(249), 'problem');
    assert.equal(at(250), 'ok');
    assert.equal(at(299), 'ok');
    assert.equal(at(300), 'good');
  });

  test('cornerstone: <400 problem, 400–899 ok, 900+ good', () => {
    assert.equal(at(399, true), 'problem');
    assert.equal(at(400, true), 'ok');
    assert.equal(at(899, true), 'ok');
    assert.equal(at(900, true), 'good');
  });

  test('headings count; script content does not', () => {
    assert.equal(band(seo({ html: `<h2>${filler(100)}</h2>${p(filler(200))}<script>${filler(500)}</script>` }, 'textLength')), 'good');
    assert.equal(band(seo({ html: `${p(filler(200))}<script>${filler(500)}</script>` }, 'textLength')), 'problem');
  });
});

describe('internal and outbound links', () => {
  const at = (html: string, id: string, extra: { siteHost?: string; url?: string } = {}): string =>
    band(seo({ html, ...extra }, id));

  test('internal: none problem, only nofollow ok, a followed one good', () => {
    assert.equal(at(p('No links.'), 'internalLinks'), 'problem');
    assert.equal(at('<a href="/about" rel="nofollow">About</a>', 'internalLinks'), 'ok');
    assert.equal(at('<a href="/about">About</a>', 'internalLinks'), 'good');
    assert.equal(at('<a href="https://www.example.com/x">X</a>', 'internalLinks'), 'good');
  });

  test('outbound: none problem, only nofollow/sponsored/ugc ok, a followed one good', () => {
    assert.equal(at('<a href="/about">About</a>', 'outboundLinks'), 'problem');
    assert.equal(at('<a href="https://other.org" rel="sponsored noopener">O</a>', 'outboundLinks'), 'ok');
    assert.equal(at('<a href="https://other.org">O</a>', 'outboundLinks'), 'good');
  });

  test('fragment, mailto, tel and javascript links are not counted', () => {
    const html = '<a href="#top">Top</a><a href="mailto:a@b.c">M</a><a href="tel:1">T</a><a href="javascript:void(0)">J</a><a>None</a>';
    assert.equal(at(html, 'internalLinks'), 'problem');
    assert.equal(at(html, 'outboundLinks'), 'problem');
  });

  test('siteHost wins over the page URL host', () => {
    const html = '<a href="https://example.com/x">X</a>';
    assert.equal(at(html, 'outboundLinks', { siteHost: 'other.org' }), 'good');
    assert.equal(at(html, 'internalLinks', { siteHost: 'https://www.Example.com:8443' }), 'good');
  });

  test('classifyLink without any known host treats absolute links as outbound', () => {
    assert.equal(siteHostOf(undefined, '/path/only'), '');
    assert.equal(classifyLink('https://example.com/', ''), 'outbound');
    assert.equal(classifyLink('//cdn.example.com/x', 'example.com'), 'outbound');
    assert.equal(classifyLink('relative/page', ''), 'internal');
    assert.equal(classifyLink('ftp://example.com/', 'example.com'), 'ignored');
  });
});

describe('cornerstone', () => {
  // A page that sits in the "ok" band of every cornerstone-sensitive check.
  const input = {
    html: '<h1>Sourdough bread recipe</h1>' + p('Sourdough bread recipe for all. <a href="/a">A</a> <a href="https://b.org">B</a>')
      + '<h2>Sourdough bread steps</h2><img src="a.jpg" alt="Sourdough bread recipe">' + p(filler(320)),
    title: 'Sourdough bread recipe: the complete guide for you',
    description: chars(130) + ' sourdough bread recipe',
    url: '/sourdough-bread',
    keyphrase: 'sourdough bread recipe',
    usedKeyphrases: [],
  };

  test('changes only the bands of the documented SEO checks', () => {
    const regular = run({ ...input, cornerstone: false }).seo.assessments;
    const corner = run({ ...input, cornerstone: true }).seo.assessments;
    assert.deepEqual(corner.map((a) => a.id), regular.map((a) => a.id));
    const changed = corner.filter((a, i) => a.band !== regular[i]?.band).map((a) => a.id).sort();
    assert.deepEqual(changed, ['keyphraseInSlug', 'textLength']);
  });
});

describe('overall SEO score', () => {
  test('is the mean of good 100 / ok 50 / problem 0', () => {
    const result = run({ keyphrase: '' });
    const expected = Math.round(
      result.seo.assessments.reduce((sum, a) => sum + ({ good: 100, ok: 50, problem: 0 })[a.band], 0) / result.seo.assessments.length,
    );
    assert.equal(result.seo.score, expected);
    // Empty page, no keyphrase: everything is a problem.
    assert.equal(result.seo.score, 0);
  });

  test('a well-optimised page scores 100', () => {
    const result = run({
      html: '<h1>Sourdough bread</h1>' + p('Sourdough bread is easy to bake. <a href="/more">More</a> and <a href="https://b.org">B</a>.')
        + '<h2>Sourdough bread steps</h2><img src="a.jpg" alt="Sourdough bread">' + p(filler(300)),
      title: 'Sourdough bread: a complete guide to baking it',
      description: chars(110) + ' sourdough bread recipe',
      url: '/sourdough-bread',
      keyphrase: 'sourdough bread',
      usedKeyphrases: [],
    });
    assert.deepEqual(result.seo.assessments.filter((a) => a.band !== 'good').map((a) => `${a.id}: ${a.message}`), []);
    assert.equal(result.seo.score, 100);
  });
});
