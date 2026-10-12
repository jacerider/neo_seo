import assert from 'node:assert/strict';
import { describe, test } from 'node:test';
import { altIsMissing } from '../../src/js/engine/structure/index.ts';
import type { Assessment } from '../../src/js/engine/index.ts';
import { p, run } from './helpers.ts';

function heading(html: string, id: string): Assessment | undefined {
  return run({ html }).structure.headings.find((a) => a.id === id);
}

function alt(html: string): Assessment | undefined {
  return run({ html }).structure.images.find((a) => a.id === 'imageAltText');
}

describe('heading outline', () => {
  test('lists every heading in order, flagging extra H1s and skipped levels', () => {
    const { outline } = run({ html: '<h1>Acme</h1><h2>Products</h2><h4>Anvils</h4><h1>Again</h1><h2>Contact</h2>' }).structure;
    assert.deepEqual(outline, [
      { level: 1, text: 'Acme' },
      { level: 2, text: 'Products' },
      { level: 4, text: 'Anvils', issue: 'skippedLevel' },
      { level: 1, text: 'Again', issue: 'extraH1' },
      { level: 2, text: 'Contact' },
    ]);
  });

  test('one H1 is good', () => {
    assert.equal(heading('<h1>Acme</h1>' + p('Text.'), 'singleH1')?.band, 'good');
  });

  test('no H1 is a problem', () => {
    const found = heading('<h2>Products</h2>' + p('Text.'), 'singleH1');
    assert.equal(found?.band, 'problem');
    assert.match(found?.message ?? '', /no H1/);
  });

  test('more than one H1 is a problem that points at each', () => {
    const found = heading('<h1>Acme</h1><h1>Acme anvils</h1>', 'singleH1');
    assert.equal(found?.band, 'problem');
    assert.match(found?.message ?? '', /2 H1 headings/);
    assert.deepEqual(found?.highlights, [
      { kind: 'heading', level: 1, text: 'Acme' },
      { kind: 'heading', level: 1, text: 'Acme anvils' },
    ]);
  });

  test('levels that follow each other are good, going back up included', () => {
    assert.equal(heading('<h1>A</h1><h2>B</h2><h3>C</h3><h2>D</h2><h3>E</h3>', 'headingLevels')?.band, 'good');
  });

  test('a skipped level is flagged with the heading that skips', () => {
    const found = heading('<h1>A</h1><h2>B</h2><h4>Skips</h4>', 'headingLevels');
    assert.equal(found?.band, 'ok');
    assert.match(found?.message ?? '', /H2 → H4/);
    assert.deepEqual(found?.highlights, [{ kind: 'heading', level: 4, text: 'Skips' }]);
  });

  test('H1 straight to H3 is a skip; several skips are counted', () => {
    const found = heading('<h1>A</h1><h3>B</h3><h5>C</h5>', 'headingLevels');
    assert.equal(found?.band, 'ok');
    assert.match(found?.message ?? '', /^2 headings skip a level/);
  });

  test('levels are not judged with fewer than two headings', () => {
    assert.equal(heading('<h3>Only</h3>', 'headingLevels'), undefined);
  });
});

describe('image alt text', () => {
  test('images with descriptive alt text are good', () => {
    assert.equal(alt('<img src="/a/anvil.jpg" alt="A red anvil on a workbench">')?.band, 'good');
  });

  test('missing, empty and file-name alt text are flagged', () => {
    const found = alt([
      '<img src="/a/anvil.jpg" alt="A red anvil">',
      '<img src="/a/rocket.jpg">',
      '<img src="/a/coyote.png" alt="">',
      '<img src="/styles/large/public/coyote-trap_2.PNG.avif?itok=x" alt="coyote-trap_2.PNG">',
    ].join(''));
    assert.equal(found?.band, 'problem');
    assert.match(found?.message ?? '', /^3 of 4 images/);
    assert.deepEqual(found?.highlights?.map((h) => (h.kind === 'image' ? h.src : '')), [
      '/a/rocket.jpg',
      '/a/coyote.png',
      '/styles/large/public/coyote-trap_2.PNG.avif?itok=x',
    ]);
  });

  test('alt text that is just the file name is missing; anything more is fine', () => {
    assert.equal(altIsMissing('/a/anvil.jpg', 'Anvil'), true);
    assert.equal(altIsMissing('/a/anvil.jpg', 'An anvil'), false);
  });

  test('no images, no check', () => {
    assert.equal(alt(p('Text only.')), undefined);
  });
});

describe('structure is not scored', () => {
  test('the SEO and readability scores do not move with it', () => {
    const clean = run({ html: '<h1>A</h1>' + p('Words here.') });
    const broken = run({ html: '<h1>A</h1><h1>B</h1><img src="/x.jpg">' + p('Words here.') });
    assert.equal(broken.structure.headings[0]?.band, 'problem');
    assert.equal(clean.seo.assessments.some((a) => a.id === 'singleH1'), false);
    assert.equal(clean.readability.score, broken.readability.score);
  });
});
