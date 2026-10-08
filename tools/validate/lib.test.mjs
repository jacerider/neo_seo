/**
 * Tests for the structured-data checks that need no packages.
 *
 *   node --test tools/validate/lib.test.mjs
 */

import assert from 'node:assert/strict';
import { test } from 'node:test';
import { dangling, scripts } from './lib.mjs';

const page = (json) => `<head><script type="application/ld+json">${typeof json === 'string' ? json : JSON.stringify(json)}</script></head>`;

test('a connected graph has no dangling references', () => {
  const graph = {
    '@graph': [
      { '@type': 'WebSite', '@id': 'https://x/#website', publisher: { '@id': 'https://x/#organization' } },
      { '@type': 'Organization', '@id': 'https://x/#organization' },
      { '@type': ['WebPage', 'AboutPage'], '@id': 'https://x/a', isPartOf: { '@id': 'https://x/#website' } },
    ],
  };
  assert.deepEqual(dangling(graph), []);
});

test('a reference to a node that is not in the graph is reported with its path', () => {
  const graph = {
    '@graph': [
      { '@type': 'Article', '@id': 'https://x/a#article', author: [{ '@id': 'https://x/#/schema/person/1' }] },
    ],
  };
  assert.deepEqual(dangling(graph), ['Article.author[0] → https://x/#/schema/person/1']);
});

test('a node is not mistaken for a reference', () => {
  const graph = { '@graph': [{ '@type': 'WebPage', '@id': 'https://x/a', image: { '@type': 'ImageObject', '@id': 'https://x/i' } }] };
  assert.deepEqual(dangling(graph), []);
});

test('every ld+json script is found, and a broken one is reported', () => {
  const found = scripts(page({ '@graph': [] }) + page('{not json'));
  assert.equal(found.length, 2);
  assert.ok(found[0].json);
  assert.match(found[1].error, /JSON/);
});
