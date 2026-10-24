/**
 * Validates the structured data of pages drush neo-seo:validate fetched.
 *
 *   node validate.mjs <pages.json> <schemaorg-all-https.jsonld>
 *
 * pages.json is a list of {url, status, html}. Prints one JSON document to
 * stdout: a list of {url, status, scripts, types, dangling, issues}. Works
 * offline: Drupal fetches the pages and the schema.org vocabulary.
 *
 * Two checks per page:
 * - every {"@id": …} reference in a JSON-LD script names a node in that same
 *   script's graph — the connected-graph rule neo_seo is held to;
 * - @adobe/structured-data-validator: schema.org and Google rich-result rules.
 */

import { readFileSync } from 'node:fs';
import { dangling, scripts } from './lib.mjs';

/**
 * Rules the Adobe validator reports as errors that are not.
 *
 * Google's Image metadata rich result needs one of creator, creditText,
 * copyrightNotice or license, but only an image that wants that rich result
 * has to have one: an ImageObject without them is valid and simply not
 * eligible. Yoast's graph carries ImageObjects without them too.
 *
 * The validator holds every ListItem to the breadcrumb's rule, a name. The
 * ItemList of a summary page, Google's carousel form, gives each only a
 * position and the url of the item's own page. A BreadcrumbList's ListItems
 * still need their names. Reported as warnings instead, with the reason.
 */
const NOT_ERRORS = [
  {
    type: 'ImageObject',
    match: /Required attribute "creator" is missing or Required attribute "creditText" is missing/,
    note: 'Only needed for the Image metadata rich result; an ImageObject without it is valid.',
  },
  {
    type: 'ItemList',
    match: /Required attribute "name" is missing or Required attribute "item.name" is missing/,
    note: 'A summary page\'s ItemList gives each item a position and a url; a name is only needed where the item is described in place.',
  },
];

function fail(message) {
  process.stdout.write(JSON.stringify({ error: message }));
  process.exit(2);
}

const [pagesPath, vocabularyPath] = process.argv.slice(2);
if (!pagesPath || !vocabularyPath) {
  fail('Usage: node validate.mjs <pages.json> <schemaorg-all-https.jsonld>');
}

let Validator;
let WebAutoExtractor;
try {
  ({ default: Validator } = await import('@adobe/structured-data-validator'));
  ({ default: WebAutoExtractor } = await import('@marbec/web-auto-extractor'));
}
catch (error) {
  fail(`The validator packages are not installed (${error.code ?? error.message}).`);
}

const pages = JSON.parse(readFileSync(pagesPath, 'utf8'));
const validator = new Validator(JSON.parse(readFileSync(vocabularyPath, 'utf8')));

const results = [];
for (const page of pages) {
  const result = { url: page.url, status: page.status, scripts: 0, types: [], dangling: [], issues: [] };
  for (const script of scripts(page.html ?? '')) {
    result.scripts++;
    if (script.error) {
      result.issues.push({ severity: 'ERROR', type: 'JSON-LD', message: `Not valid JSON: ${script.error}` });
      continue;
    }
    const nodes = Array.isArray(script.json['@graph']) ? script.json['@graph'] : [script.json];
    result.types.push(...nodes.map((node) => [node?.['@type']].flat().join('+')));
    result.dangling.push(...dangling(script.json));
  }
  const extracted = new WebAutoExtractor({ addLocation: true, embedSource: ['rdfa', 'microdata'] }).parse(page.html ?? '');
  for (const issue of await validator.validate(extracted)) {
    const type = issue.path?.[0]?.type ?? '';
    const known = issue.severity === 'ERROR' && NOT_ERRORS.find((rule) => rule.type === type && rule.match.test(issue.issueMessage));
    result.issues.push({
      severity: known ? 'WARNING' : issue.severity,
      type,
      message: known ? `${issue.issueMessage} (${known.note})` : issue.issueMessage,
    });
  }
  results.push(result);
}

process.stdout.write(JSON.stringify(results));
