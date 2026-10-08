import assert from 'node:assert/strict';
import { describe, test } from 'node:test';
import { decodeEntities, tokenize } from '../../src/js/engine/html.ts';
import { buildPaper } from '../../src/js/engine/paper.ts';

describe('decodeEntities', () => {
  test('decodes the common named entities', () => {
    assert.equal(decodeEntities('Tom &amp; Jerry &quot;hi&quot; &lt;b&gt;'), 'Tom & Jerry "hi" <b>');
    assert.equal(decodeEntities('a&nbsp;b'), 'a b');
    assert.equal(decodeEntities('&eacute;t&eacute; &Uuml;ber &ccedil;a'), 'été Über ça');
    assert.equal(decodeEntities('&rsquo;&mdash;&hellip;'), '’—…');
  });

  test('decodes decimal and hex references, with or without the semicolon', () => {
    assert.equal(decodeEntities('it&#39;s &#x27;quoted&#X27; &#233;'), "it's 'quoted' é");
    assert.equal(decodeEntities('&#128512;'), '\u{1F600}');
    assert.equal(decodeEntities('&#39'), "'");
  });

  test('leaves unknown and malformed references alone', () => {
    assert.equal(decodeEntities('&bogus; & &; &amp'), '&bogus; & &; &');
    assert.equal(decodeEntities('&eacute'), '&eacute');
    assert.equal(decodeEntities('&#0; &#xD800;'), '� �');
  });
});

describe('tokenize', () => {
  test('parses quoted, unquoted and bare attributes', () => {
    const [img] = tokenize(`<img src='/a.png' alt="A &amp; B" width=10 hidden>`);
    assert.deepEqual(img, {
      type: 'open',
      name: 'img',
      attrs: { src: '/a.png', alt: 'A & B', width: '10', hidden: '' },
      selfClosing: false,
    });
  });

  test('drops comments, doctype, CDATA and raw-text elements', () => {
    const html = '<!doctype html><!-- <p>no</p> --><script>var p = "<p>no</p>";</script>'
      + '<style>p{}</style><noscript><img src="x"></noscript><![CDATA[ <p>no</p> ]]>yes';
    assert.deepEqual(tokenize(html), [{ type: 'text', text: 'yes' }]);
  });

  test('treats a stray < as text', () => {
    const texts = tokenize('a < b and 3<4 </ c').filter((t) => t.type === 'text').map((t) => t.text).join('');
    assert.equal(texts, 'a < b and 3<4 </ c');
  });

  test('survives an unterminated tag and an unterminated script', () => {
    assert.doesNotThrow(() => tokenize('<p class="x'));
    assert.deepEqual(tokenize('ok<script>never closed <p>'), [{ type: 'text', text: 'ok' }]);
  });
});

describe('buildPaper', () => {
  test('splits headings and paragraphs in document order', () => {
    const paper = buildPaper('<h1>Title</h1><p>One.</p><h2>Sub</h2><p>Two.</p><h3>Deep</h3><ul><li>A</li><li>B</li></ul>');
    assert.deepEqual(
      paper.blocks.map((b) => (b.kind === 'heading' ? `h${b.level}:${b.text}` : `${b.tag}:${b.text}`)),
      ['h1:Title', 'p:One.', 'h2:Sub', 'p:Two.', 'h3:Deep', 'li:A', 'li:B'],
    );
    assert.equal(paper.text, 'Title\n\nOne.\n\nSub\n\nTwo.\n\nDeep\n\nA\n\nB');
  });

  test('ignores script, style, template, noscript, svg and comments', () => {
    const paper = buildPaper(
      '<p>Kept</p><script>Dropped</script><style>.x{}</style><template><p>Dropped</p></template>'
      + '<noscript>Dropped</noscript><svg><title>Dropped</title><text>Dropped</text></svg><!-- Dropped -->',
    );
    assert.equal(paper.text, 'Kept');
  });

  test('handles unclosed p and li, void and self-closing elements', () => {
    const paper = buildPaper('<p>First<p>Second<br>line<br/>end<ul><li>One<li>Two</ul><hr/><p>Last <img src="a.jpg"/> word');
    assert.deepEqual(paper.paragraphs.map((b) => b.text), ['First', 'Second line end', 'One', 'Two', 'Last word']);
    assert.equal(paper.images.length, 1);
  });

  test('joins inline elements without inventing spaces and collapses whitespace', () => {
    const paper = buildPaper('<p>  Un<strong>believ</strong>able\n\n  <em>text</em>&nbsp;here. </p>');
    assert.equal(paper.paragraphs[0]?.text, 'Unbelievable text here.');
  });

  test('a block wrapper inside a heading does not cut the heading short', () => {
    const paper = buildPaper('<h2><span>Big</span> <div>title</div></h2><p>Body</p>');
    assert.deepEqual(paper.headings.map((h) => h.text), ['Big title']);
  });

  test('collects images with their alt, distinguishing a missing alt from an empty one', () => {
    const paper = buildPaper('<img src="a.jpg" alt="A dog"><img src="b.jpg" alt=""><img src="c.jpg">');
    assert.deepEqual(paper.images, [
      { src: 'a.jpg', alt: 'A dog' },
      { src: 'b.jpg', alt: '' },
      { src: 'c.jpg', alt: null },
    ]);
  });

  test('collects links with href, rel and text; link text stays in the paragraph', () => {
    const paper = buildPaper('<p>See <a href="/about" rel="NoFollow">our <b>team</b></a> now.</p>');
    assert.deepEqual(paper.links, [{ href: '/about', rel: 'nofollow', text: 'our team' }]);
    assert.equal(paper.paragraphs[0]?.text, 'See our team now.');
  });

  test('the introduction is the first <p>, skipping eyebrow divs and headings', () => {
    const paper = buildPaper('<div class="eyebrow">Services</div><h1>Title</h1><p>The intro.</p><p>More.</p>');
    assert.equal(paper.introduction, 'The intro.');
  });

  test('without any <p>, the introduction is the first paragraph-like block', () => {
    assert.equal(buildPaper('<h2>Head</h2><div>Loose text</div><li>Item</li>').introduction, 'Loose text');
    assert.equal(buildPaper('<h2>Only a heading</h2>').introduction, '');
  });

  test('decodes entities in text and attributes', () => {
    const paper = buildPaper('<p>Caf&eacute; &amp; bar</p><img src="x.png" alt="Caf&#233;">');
    assert.equal(paper.text, 'Café & bar');
    assert.equal(paper.images[0]?.alt, 'Café');
  });

  test('an empty document is an empty paper', () => {
    const paper = buildPaper('');
    assert.equal(paper.blocks.length, 0);
    assert.equal(paper.text, '');
    assert.equal(paper.introduction, '');
  });
});
