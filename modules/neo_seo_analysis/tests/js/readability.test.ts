import assert from 'node:assert/strict';
import { describe, test } from 'node:test';
import { thresholds } from '../../src/js/engine/thresholds.ts';
import { fleschBand, fleschFormula, fleschStats } from '../../src/js/engine/readability/flesch.ts';
import { beginning, hasTransition } from '../../src/js/engine/readability/flow.ts';
import { isPassive } from '../../src/js/engine/readability/passive.ts';
import { normalizedWords, splitSentences } from '../../src/js/engine/text.ts';
import { band, filler, p, readability, run } from './helpers.ts';

/** Sentences of the given lengths, each in its own paragraph, with varied first words. */
function sentences(lengths: number[]): string {
  return lengths.map((n, i) => p(filler(n, n, i * 7))).join('');
}

function stats(text: string): ReturnType<typeof fleschStats> {
  return fleschStats(splitSentences(text).map(normalizedWords));
}

describe('language support', () => {
  test('non-English returns an explicit not-available result, never scores', () => {
    for (const language of ['fr', 'de-DE', 'und', '']) {
      const result = run({ language, html: p(filler(400)) });
      assert.equal(result.readability.available, false, language);
      assert.equal(result.readability.score, 0);
      assert.deepEqual(result.readability.assessments, []);
      assert.match(result.readability.message ?? '', /only available for English/);
    }
  });

  test('non-English pages still get the SEO checks', () => {
    const result = run({ language: 'fr', keyphrase: 'pain', html: p('Le pain est bon.') });
    assert.ok(result.seo.assessments.some((a) => a.id === 'keyphraseDensity'));
  });

  test('English variants are analysed', () => {
    for (const language of ['en', 'en-US', 'en_GB']) {
      assert.equal(run({ language, html: p('Short text.') }).readability.available, true, language);
    }
  });

  test('an English page without prose gets a single text-presence problem', () => {
    const result = run({ html: '<h2>Only a heading</h2><img src="a.jpg">' });
    assert.deepEqual(result.readability.assessments.map((a) => `${a.id}:${a.band}`), ['textPresence:problem']);
    assert.equal(result.readability.score, 0);
  });
});

describe('Flesch reading ease', () => {
  test('the formula matches published reference values', () => {
    // Wikipedia's "Flesch–Kincaid readability tests": "The cat sat on the mat." scores 116.
    const cat = stats('The cat sat on the mat.');
    assert.deepEqual([cat.words, cat.sentences, cat.syllables], [6, 1, 6]);
    assert.equal(Math.round(cat.score), 116);
    // Same source: "This sentence, taken as a reading passage unto itself, is being used to
    // prove a point." has a reading ease of 69 (16 words, 23 syllables).
    const point = stats('This sentence, taken as a reading passage unto itself, is being used to prove a point.');
    assert.deepEqual([point.words, point.sentences, point.syllables], [16, 1, 23]);
    assert.equal(Math.round(point.score), 69);
  });

  test('matches a hand computation over several sentences', () => {
    // 14 words, 2 sentences, 14 syllables: 206.835 − 1.015 × 7 − 84.6 × 1 = 115.13
    const s = stats('The cat sat on the mat. The dog ran to the big red barn.');
    assert.deepEqual([s.words, s.sentences, s.syllables], [14, 2, 14]);
    assert.ok(Math.abs(s.score - 115.13) < 1e-9);
    // 6 words, 2 sentences, 20 syllables: 206.835 − 1.015 × 3 − 84.6 × 20/6 = −78.21
    const hard = stats('Education is important. Universities provide opportunities.');
    assert.deepEqual([hard.words, hard.sentences, hard.syllables], [6, 2, 20]);
    assert.ok(Math.abs(hard.score - -78.21) < 1e-9);
    assert.equal(fleschFormula(0, 0, 0), 0);
  });

  test('bands: 60+ good, 50 to under 60 ok, under 50 problem', () => {
    const t = thresholds(false);
    assert.equal(fleschBand(60, t), 'good');
    assert.equal(fleschBand(59.99, t), 'ok');
    assert.equal(fleschBand(50, t), 'ok');
    assert.equal(fleschBand(49.99, t), 'problem');
  });

  test('easy and hard texts land in the right band end to end', () => {
    assert.equal(band(readability({ html: p('The cat sat on the mat. The dog ran to the barn.') }, 'fleschReadingEase')), 'good');
    assert.equal(band(readability({ html: p('Education is important. Universities provide opportunities.') }, 'fleschReadingEase')), 'problem');
  });

  test('the message shows the score clamped to 0–100', () => {
    assert.match(readability({ html: p('The cat sat on the mat.') }, 'fleschReadingEase')?.message ?? '', / 100 /);
  });
});

describe('sentence length', () => {
  /** 20 sentences, `long` of them 21 words, the rest 5 words. */
  const at = (long: number, cornerstone = false): string => {
    const lengths = Array.from({ length: 20 }, (_, i) => (i < long ? 21 : 5));
    return band(readability({ html: sentences(lengths), cornerstone }, 'sentenceLength'));
  };

  test('regular: ≤25% good, ≤30% ok, more problem', () => {
    assert.equal(at(5), 'good'); // 25%
    assert.equal(at(6), 'ok'); // 30%
    assert.equal(at(7), 'problem'); // 35%
  });

  test('cornerstone: ≤20% good, ≤25% ok, more problem', () => {
    assert.equal(at(4, true), 'good'); // 20%
    assert.equal(at(5, true), 'ok'); // 25%
    assert.equal(at(6, true), 'problem'); // 30%
  });

  test('exactly 20 words is not long', () => {
    assert.equal(band(readability({ html: sentences([20, 20, 20]) }, 'sentenceLength')), 'good');
    assert.equal(band(readability({ html: sentences([21, 21, 21]) }, 'sentenceLength')), 'problem');
  });

  test('highlights the long sentences', () => {
    const found = readability({ html: sentences([21, 5]) }, 'sentenceLength');
    assert.equal(found?.highlights?.length, 1);
    assert.equal(found?.highlights?.[0]?.kind, 'sentence');
  });
});

describe('paragraph length', () => {
  const at = (n: number): string => band(readability({ html: p(filler(n)) + p(filler(10)) }, 'paragraphLength'));

  test('≤150 words good, 151–200 ok, over 200 problem', () => {
    assert.equal(at(150), 'good');
    assert.equal(at(151), 'ok');
    assert.equal(at(200), 'ok');
    assert.equal(at(201), 'problem');
  });

  test('highlights the long paragraphs', () => {
    assert.equal(readability({ html: p(filler(160)) }, 'paragraphLength')?.highlights?.[0]?.kind, 'paragraph');
  });
});

describe('subheading distribution', () => {
  const at = (html: string, cornerstone = false): string => band(readability({ html, cornerstone }, 'subheadingDistribution'));

  test('without subheadings: up to 300 words good, more problem (an H1 is not a subheading)', () => {
    assert.equal(at('<h1>Title</h1>' + p(filler(300))), 'good');
    assert.equal(at('<h1>Title</h1>' + p(filler(301))), 'problem');
  });

  test('with subheadings: sections ≤300 good, ≤350 ok, longer problem', () => {
    assert.equal(at(p(filler(100)) + '<h2>A</h2>' + p(filler(150)) + p(filler(150))), 'good');
    assert.equal(at(p(filler(100)) + '<h2>A</h2>' + p(filler(150)) + p(filler(151))), 'ok');
    assert.equal(at(p(filler(350)) + '<h3>A</h3>' + p(filler(10))), 'ok');
    assert.equal(at(p(filler(351)) + '<h3>A</h3>' + p(filler(10))), 'problem');
  });

  test('cornerstone: sections ≤250 good, ≤300 ok, longer problem; 250 without subheadings', () => {
    assert.equal(at('<h2>A</h2>' + p(filler(250)), true), 'good');
    assert.equal(at('<h2>A</h2>' + p(filler(251)), true), 'ok');
    assert.equal(at('<h2>A</h2>' + p(filler(300)), true), 'ok');
    assert.equal(at('<h2>A</h2>' + p(filler(301)), true), 'problem');
    assert.equal(at(p(filler(251)), true), 'problem');
  });

  test('highlights the subheading over a long section', () => {
    const found = readability({ html: '<h2>Short</h2>' + p(filler(10)) + '<h2>Long</h2>' + p(filler(320)) }, 'subheadingDistribution');
    assert.deepEqual(found?.highlights, [{ kind: 'heading', level: 2, text: 'Long' }]);
  });
});

describe('passive voice detection', () => {
  test('detects be + participle, regular and irregular, with adverbs between', () => {
    for (const s of [
      'The house was built in 1900.',
      'The cake is eaten every day.',
      'Mistakes were made.',
      'The road is being repaired.',
      'The plan has been quickly approved.',
      'It was not written by him.',
      'The results are often ignored.',
      "They're told to wait.",
      "It's been decided.",
      "The form wasn't signed.",
      'You will be paid on Friday.',
    ]) {
      assert.equal(isPassive(s), true, s);
    }
  });

  test('does not flag active sentences', () => {
    for (const s of [
      'The builder built the house.',
      'She is reading a book.',
      'The sky is red.',
      'He was indeed happy.',
      'We need more bread.',
      'They were at home.',
      'The wind is speed.',
    ]) {
      assert.equal(isPassive(s), false, s);
    }
  });

  describe('known limitations (pinned so a fix shows up as a failing test)', () => {
    test('KNOWN MISS: get-passive', () => {
      assert.equal(isPassive('The cake got eaten.'), false);
    });
    test("KNOWN MISS: 's contraction of is", () => {
      assert.equal(isPassive("It's thought that prices will rise."), false);
    });
    test('KNOWN MISS: reduced relative clause without a be-verb', () => {
      assert.equal(isPassive('The report, written by the board, is long.'), false);
    });
    test('KNOWN MISS: more than two adverbs between auxiliary and participle', () => {
      assert.equal(isPassive('It was very carefully and thoroughly checked.'), false);
    });
    test('KNOWN MISS: irregular participles missing from the list', () => {
      assert.equal(isPassive('The deal was inlaid with gold.'), false);
    });
    test('KNOWN FALSE POSITIVE: adjectival participles count as passive', () => {
      assert.equal(isPassive('She was tired.'), true);
      assert.equal(isPassive('They are interested in art.'), true);
      assert.equal(isPassive('I am used to it.'), true);
    });
  });
});

describe('passive voice assessment', () => {
  /** 20 sentences, `k` of them passive. */
  const at = (k: number): string => {
    const html = Array.from({ length: 20 }, (_, i) => p(i < k ? `Wall ${i} was painted.` : filler(5, 5, i * 7))).join('');
    return band(readability({ html }, 'passiveVoice'));
  };

  test('≤10% good, ≤15% ok, more problem', () => {
    assert.equal(at(0), 'good');
    assert.equal(at(2), 'good'); // 10%
    assert.equal(at(3), 'ok'); // 15%
    assert.equal(at(4), 'problem'); // 20%
  });

  test('highlights the passive sentences', () => {
    const html = Array.from({ length: 4 }, (_, i) => p(i === 0 ? 'The wall was painted.' : filler(5, 5, i * 7))).join('');
    assert.deepEqual(readability({ html }, 'passiveVoice')?.highlights, [{ kind: 'sentence', text: 'The wall was painted.' }]);
  });
});

describe('transition words', () => {
  test('finds single words and whole multi-word phrases', () => {
    assert.equal(hasTransition(normalizedWords('However, it rained.')), true);
    assert.equal(hasTransition(normalizedWords('Bring a coat, for example.')), true);
    assert.equal(hasTransition(normalizedWords('On the other hand, it was warm.')), true);
    assert.equal(hasTransition(normalizedWords('The forex ample market.')), false);
    assert.equal(hasTransition(normalizedWords('Thusly spoke the fox.')), false);
  });

  /** 20 sentences of 10 words (200 words), `k` with a transition. */
  const at = (k: number, perSentence = 10): string | undefined => {
    const html = Array.from({ length: 20 }, (_, i) =>
      p(i < k ? `Therefore ${filler(perSentence - 1, perSentence, i * 7).toLowerCase()}` : filler(perSentence, perSentence, i * 7))).join('');
    return readability({ html }, 'transitionWords')?.band;
  };

  test('≥30% good, ≥20% ok, less problem', () => {
    assert.equal(at(6), 'good'); // 30%
    assert.equal(at(5), 'ok'); // 25%
    assert.equal(at(4), 'ok'); // 20%
    assert.equal(at(3), 'problem'); // 15%
  });

  test('does not run under 200 words', () => {
    assert.equal(at(0, 10), 'problem');
    assert.equal(at(0, 9), undefined); // 180 words
  });
});

describe('consecutive sentence beginnings', () => {
  const at = (html: string): string => band(readability({ html }, 'sentenceBeginnings'));

  test('two in a row is fine, three is a problem', () => {
    assert.equal(at(p('Bread is good. Bread is warm. Cake is sweet.')), 'good');
    assert.equal(at(p('Bread is good. Bread is warm. Bread is cheap.')), 'problem');
  });

  test('runs continue across paragraphs but a heading breaks them', () => {
    assert.equal(at(p('Bread is good.') + p('Bread is warm.') + p('Bread is cheap.')), 'problem');
    assert.equal(at(p('Bread is good. Bread is warm.') + '<h2>More</h2>' + p('Bread is cheap.')), 'good');
  });

  test('articles and demonstratives take the next word into account', () => {
    assert.equal(beginning(normalizedWords('The dog ran.')), 'the dog');
    assert.equal(at(p('The dog ran. The cat sat. The bird sang.')), 'good');
    assert.equal(at(p('The dog ran. The dog sat. The dog sang.')), 'problem');
  });

  test('matching ignores case and diacritics; highlights every sentence in the run', () => {
    const found = readability({ html: p('Éclair one. Eclair two? ECLAIR three!') }, 'sentenceBeginnings');
    assert.equal(found?.band, 'problem');
    assert.equal(found?.highlights?.length, 3);
  });
});

describe('cornerstone and readability', () => {
  test('changes only sentence length and subheading distribution', () => {
    // 20 sentences, 5 of them over 20 words (25%): regular good, cornerstone ok.
    // The second section has 280 words: regular good, cornerstone ok.
    const html = '<h2>Intro</h2>' + sentences([21, 21, 21, 21, 21, 5])
      + '<h2>More</h2>' + p(filler(140, 20)) + p(filler(140, 20));
    const regular = run({ html, cornerstone: false }).readability.assessments;
    const corner = run({ html, cornerstone: true }).readability.assessments;
    assert.deepEqual(corner.map((a) => a.id), regular.map((a) => a.id));
    const changed = corner.filter((a, i) => a.band !== regular[i]?.band).map((a) => a.id).sort();
    assert.deepEqual(changed, ['sentenceLength', 'subheadingDistribution']);
  });
});

describe('overall readability score', () => {
  test('is the mean of good 100 / ok 50 / problem 0 over the assessments that ran', () => {
    const result = run({ html: p('Bread is good. Bread is warm. Bread is cheap.') });
    const points = { good: 100, ok: 50, problem: 0 };
    const expected = Math.round(result.readability.assessments.reduce((s, a) => s + points[a.band], 0) / result.readability.assessments.length);
    assert.equal(result.readability.score, expected);
    // 6 assessments run (under 200 words: no transition check); only beginnings fails.
    assert.equal(result.readability.assessments.length, 6);
    assert.equal(result.readability.score, 83);
  });
});
