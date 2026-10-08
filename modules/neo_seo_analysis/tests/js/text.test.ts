import assert from 'node:assert/strict';
import { describe, test } from 'node:test';
import {
  containsAll, countInSentence, countSyllables, exactPositions, isEnglish, normalizeWord,
  normalizedWords, parseKeyphrase, phraseKey, splitSentences, words,
} from '../../src/js/engine/text.ts';

describe('words', () => {
  test('keeps hyphenated words and contractions whole, drops punctuation', () => {
    assert.deepEqual(words("It's a well-known, rock’n’roll fact (2026)!"), ["It's", 'a', 'well-known', 'rock’n’roll', 'fact', '2026']);
  });

  test('normalizes case, diacritics and apostrophes', () => {
    assert.equal(normalizeWord('Crème'), 'creme');
    assert.equal(normalizeWord('Don’t'), "don't");
    assert.deepEqual(normalizedWords('ÉCOLE Ångström'), ['ecole', 'angstrom']);
  });
});

describe('isEnglish', () => {
  test('accepts en and its regional variants only', () => {
    for (const code of ['en', 'EN', 'en-US', 'en_GB', ' en-au ']) {
      assert.equal(isEnglish(code), true, code);
    }
    for (const code of ['', 'fr', 'eng', 'es-EN', 'de']) {
      assert.equal(isEnglish(code), false, code);
    }
  });
});

describe('splitSentences', () => {
  test('splits on . ! ? and the ellipsis character', () => {
    assert.deepEqual(splitSentences('One. Two! Three? Four… Five'), ['One.', 'Two!', 'Three?', 'Four…', 'Five']);
  });

  test('keeps closing quotes and brackets with their sentence', () => {
    assert.deepEqual(splitSentences('He said "Stop." Then (quietly.) Went.'), ['He said "Stop."', 'Then (quietly.)', 'Went.']);
  });

  test('does not split after abbreviations, initials or before lowercase', () => {
    assert.deepEqual(
      splitSentences('Dr. Smith met J. Doe at 3 p.m. today. Use e.g. this one. Done.'),
      ['Dr. Smith met J. Doe at 3 p.m. today.', 'Use e.g. this one.', 'Done.'],
    );
  });

  test('does not split decimals or URLs', () => {
    assert.deepEqual(splitSentences('It costs 3.50 at example.com today. Next.'), ['It costs 3.50 at example.com today.', 'Next.']);
  });

  test('drops empty fragments', () => {
    assert.deepEqual(splitSentences(' ... ! '), []);
  });
});

describe('countSyllables', () => {
  test('counts the words of the Flesch reference sentences exactly', () => {
    const expected: Record<string, number> = {
      the: 1, cat: 1, sat: 1, on: 1, mat: 1, this: 1, sentence: 2, taken: 2, as: 1, a: 1,
      reading: 2, passage: 2, unto: 2, itself: 2, is: 1, being: 2, used: 1, to: 1, prove: 1,
      point: 1,
    };
    for (const [word, n] of Object.entries(expected)) {
      assert.equal(countSyllables(word), n, word);
    }
  });

  test('handles silent endings and vowel pairs', () => {
    const expected: Record<string, number> = {
      make: 1, makes: 1, table: 2, tables: 2, while: 1, wanted: 2, jumped: 1, played: 1,
      boxes: 2, pages: 2, lovely: 2, management: 3, doing: 2, saying: 2, media: 3, radio: 3,
      nation: 2, client: 2, science: 2, video: 3, universities: 5, opportunities: 5,
    };
    for (const [word, n] of Object.entries(expected)) {
      assert.equal(countSyllables(word), n, word);
    }
  });

  test('is at least 90% right on everyday words it was not tuned on', () => {
    // Dictionary syllable counts; this list was not used to write the rules.
    const heldOut: Record<string, number> = {
      garden: 2, yellow: 2, morning: 2, chocolate: 3, banana: 3, elephant: 3, umbrella: 3,
      telephone: 3, mountain: 2, kitchen: 2, window: 2, pencil: 2, bicycle: 3, hospital: 3,
      library: 3, president: 3, government: 3, development: 4, community: 4, environment: 4,
      technology: 4, university: 5, individual: 5, organization: 5, responsibility: 6,
      strength: 1, through: 1, thought: 1, laugh: 1, brought: 1, simple: 2, purple: 2,
      apple: 2, candle: 2, castle: 2, become: 2, decide: 2, complete: 2, describe: 2,
      reduce: 2, improve: 2, picture: 2, nature: 2, future: 2, measure: 2, answer: 2,
      water: 2, never: 2, river: 2, happen: 2, open: 2, often: 2, listen: 2, problem: 2,
      system: 2, number: 2, children: 2, teacher: 2, question: 2, mother: 2, country: 2,
      animal: 3, beautiful: 3, important: 3, wonderful: 3, together: 3, remember: 3,
      tomorrow: 3, potato: 3, tomato: 3, energy: 3, history: 3, holiday: 3, family: 3,
      several: 3, possible: 3, terrible: 3, comfortable: 4, vegetable: 4, temperature: 4,
      information: 4, education: 4, especially: 4, dictionary: 4, celebrated: 4, invited: 3,
      decided: 3, finished: 2, worked: 1, hoped: 1, smiled: 1, closed: 1, opened: 2,
    };
    const entries = Object.entries(heldOut);
    const misses = entries.filter(([w, n]) => countSyllables(w) !== n).map(([w, n]) => `${w} ${countSyllables(w)}≠${n}`);
    const accuracy = (entries.length - misses.length) / entries.length;
    assert.ok(accuracy >= 0.9, `accuracy ${accuracy.toFixed(2)}; misses: ${misses.join(', ')}`);
  });

  test('never returns less than one', () => {
    assert.equal(countSyllables('hmm'), 1);
    assert.equal(countSyllables('2026'), 1);
    assert.equal(countSyllables('rhythm'), 1);
  });
});

describe('keyphrase matching', () => {
  test('content words are required; function words are not (English)', () => {
    const kp = parseKeyphrase('How to bake the best bread', true);
    assert.deepEqual(kp?.required, ['bake', 'best', 'bread']);
    assert.deepEqual(kp?.words, ['how', 'to', 'bake', 'the', 'best', 'bread']);
  });

  test('a keyphrase of only function words requires all of them', () => {
    assert.deepEqual(parseKeyphrase('To be or not to be', true)?.required, ['to', 'be', 'or', 'not']);
  });

  test('other languages require every word', () => {
    assert.deepEqual(parseKeyphrase('le pain de mie', false)?.required, ['le', 'pain', 'de', 'mie']);
  });

  test('an empty or punctuation-only keyphrase is no keyphrase', () => {
    assert.equal(parseKeyphrase('', true), null);
    assert.equal(parseKeyphrase('  "" ', true), null);
  });

  test('any order, case- and diacritic-insensitive, whole words, no stemming', () => {
    const kp = parseKeyphrase('Crème Brûlée', true);
    assert.ok(kp);
    assert.equal(containsAll(normalizedWords('brulee and CREME'), kp), true);
    assert.equal(containsAll(normalizedWords('cremes brulees'), kp), false);
    assert.equal(containsAll(normalizedWords('crèmebrûlée'), kp), false);
  });

  test('exact positions find the phrase as a token sequence', () => {
    assert.deepEqual(exactPositions(['a', 'b', 'c', 'a', 'b'], ['a', 'b']), [0, 3]);
    assert.deepEqual(exactPositions(['a', 'b'], []), []);
  });

  test('counts complete sets of required words per sentence', () => {
    const kp = parseKeyphrase('dog food', true);
    assert.ok(kp);
    assert.equal(countInSentence(normalizedWords('Dog food for dogs and dog food'), kp), 2);
    assert.equal(countInSentence(normalizedWords('Food only'), kp), 0);
  });

  test('phraseKey normalizes for used-before comparison', () => {
    assert.equal(phraseKey(' "Café  Latte" '), phraseKey('cafe latte'));
  });
});
