/**
 * The suggested description. Run from the package root:
 *   node --test 'modules/neo_seo_panel/tests/js/*.test.ts'
 *
 * suggest.ts imports nothing, so the panel build imports it extensionless and
 * node loads it here by its .ts path.
 */
import assert from 'node:assert/strict';
import { describe, test } from 'node:test';
import { sentencesOf, suggestDescription } from '../../src/js/suggest.ts';

/** Six pixels a character: 920px is about 153 characters. */
const measure = (text: string) => text.length * 6;

const INTRO = 'Acme makes anvils for every kind of coyote. Our anvils are forged in small batches. '
  + 'Each one is tested on a real cliff before it ships. Orders over ten anvils ship free across the desert.';

describe('suggested description', () => {
  test('whole sentences from the first substantial paragraph, as many as fit', () => {
    const suggestion = suggestDescription([{ text: INTRO }], { measure });
    assert.equal(suggestion, 'Acme makes anvils for every kind of coyote. Our anvils are forged in small batches. '
      + 'Each one is tested on a real cliff before it ships.');
    assert.ok(measure(suggestion ?? '') <= 920);
  });

  test('short, navigation-like and empty blocks are skipped', () => {
    const suggestion = suggestDescription([
      { text: 'Home' },
      { text: 'Products Services About Contact Careers Blog News Events', linkRatio: 1 },
      { text: '' },
      { text: INTRO },
    ], { measure });
    assert.match(suggestion ?? '', /^Acme makes anvils/);
  });

  test('a sentence holding the keyphrase is preferred, in the opening paragraphs', () => {
    const suggestion = suggestDescription([
      { text: INTRO },
      { text: 'Rocket skates are our newest line of products. They reach full speed in under a second.' },
    ], { measure, keyphrase: 'rocket skates' });
    assert.equal(suggestion, 'Rocket skates are our newest line of products. They reach full speed in under a second.');
  });

  test('keyphrase matching ignores case, order and accents', () => {
    const suggestion = suggestDescription([{ text: INTRO }], { measure, keyphrase: 'Cliff TESTED' });
    assert.match(suggestion ?? '', /^Each one is tested on a real cliff/);
  });

  test('a keyphrase outside the opening paragraphs falls back to the start', () => {
    const paragraphs = [INTRO, INTRO, INTRO, 'Rocket skates are our newest line of products and they are fast.'];
    const suggestion = suggestDescription(paragraphs.map((text) => ({ text })), { measure, keyphrase: 'rocket skates' });
    assert.match(suggestion ?? '', /^Acme makes anvils/);
  });

  test('a first sentence too long to fit is cut at a word, never mid-word', () => {
    const long = 'Acme ' + 'anvils '.repeat(40).trim() + ' are heavy.';
    const suggestion = suggestDescription([{ text: long }], { measure });
    assert.ok(suggestion);
    assert.ok(measure(suggestion) <= 920, 'fits');
    assert.match(suggestion, /anvils…$/, 'ends with a whole word and an ellipsis');
  });

  test('a cut does not end on a dangling connective', () => {
    const long = 'Explore how thoughtful landscape design can transform busy hospital grounds into calm gardens '
      + 'that support comfort, wellness, and connection to the outdoors for patients and staff alike.';
    const suggestion = suggestDescription([{ text: long }], { measure, limit: 6 * 132 });
    assert.equal(suggestion, 'Explore how thoughtful landscape design can transform busy hospital grounds into calm gardens '
      + 'that support comfort, wellness…');
  });

  test('never exceeds the width, whatever the measure', () => {
    const wide = (text: string) => text.length * 11;
    const suggestion = suggestDescription([{ text: INTRO }], { measure: wide });
    assert.ok(suggestion);
    assert.ok(wide(suggestion) <= 920);
    assert.match(suggestion, /[.!?…]$/);
  });

  test('nothing usable, no suggestion', () => {
    assert.equal(suggestDescription([], { measure }), null);
    assert.equal(suggestDescription([{ text: 'Too short to say much.' }], { measure }), null);
  });

  test('a block of short lines, such as an address, is not prose', () => {
    const address = 'Headquarters\nSpringfield, Oregon\n742 Evergreen Terrace, Suite 150\nSpringfield, OR 97477\n555-0100';
    assert.equal(suggestDescription([{ text: address }], { measure }), null);
    assert.match(suggestDescription([{ text: address }, { text: INTRO }], { measure }) ?? '', /^Acme makes anvils/);
  });

  test('prose broken by a line break reads as one paragraph', () => {
    const broken = 'Acme makes anvils for every kind of coyote.\nOur anvils are forged in small batches.';
    assert.equal(suggestDescription([{ text: broken }], { measure }), 'Acme makes anvils for every kind of coyote. Our anvils are forged in small batches.');
  });
});

describe('sentences', () => {
  test('split after . ! ? before a capital, keeping quotes', () => {
    assert.deepEqual(sentencesOf('One two. "Three four!" Five? six. Seven'), ['One two.', '"Three four!"', 'Five? six.', 'Seven']);
  });
});
