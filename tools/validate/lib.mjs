/**
 * The checks of validate.mjs that need no packages and no network.
 */

/** The JSON-LD scripts of a page, parsed; an unparsable one is reported. */
export function scripts(html) {
  const found = [];
  const pattern = /<script[^>]+type=["']application\/ld\+json["'][^>]*>([\s\S]*?)<\/script>/gi;
  for (const [, body] of html.matchAll(pattern)) {
    try {
      found.push({ json: JSON.parse(body) });
    }
    catch (error) {
      found.push({ error: error.message });
    }
  }
  return found;
}

/** References in a script that name no node in its graph. */
export function dangling(json) {
  const nodes = Array.isArray(json['@graph']) ? json['@graph'] : [json];
  const ids = new Set(nodes.map((node) => node?.['@id']).filter(Boolean));
  const missing = [];
  const walk = (value, path) => {
    if (Array.isArray(value)) {
      value.forEach((item, index) => walk(item, `${path}[${index}]`));
      return;
    }
    if (!value || typeof value !== 'object') {
      return;
    }
    const keys = Object.keys(value);
    if (keys.length === 1 && keys[0] === '@id') {
      if (!ids.has(value['@id'])) {
        missing.push(`${path} → ${value['@id']}`);
      }
      return;
    }
    for (const key of keys) {
      walk(value[key], `${path}.${key}`);
    }
  };
  nodes.forEach((node) => walk(node, [node?.['@type']].flat().join('+') || 'node'));
  return missing;
}
