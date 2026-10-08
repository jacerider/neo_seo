# Neo | SEO

A connected schema.org graph, head tags and on-page SEO for Neo sites. It replaces `metatag`,
`schema_metatag` and `neo_metatag`.

- **Schema graph** — one JSON-LD `@graph` per page, assembled from **graph pieces** (plugins), with
  every `@id` generated from Yoast's conventions so the pieces link to each other.
- **Head tags** — a curated, plugin-extensible set fed by token templates and per-page overrides.
- **SEO panel** (`neo_seo_panel`) — Google and social previews in the edit form and in Alchemist.
- **Analysis** (`neo_seo_analysis`) — focus-keyphrase and readability checks.

Under construction. The design lives in the wps site's `.scratch/neo-seo/spec.md`.

## Validating structured data

```bash
ddev exec npm ci --prefix web/modules/contrib/neo_seo/tools/validate   # once
ddev drush neo-seo:validate --insecure                                 # a sample of every kind of page
ddev drush neo-seo:validate --insecure --url=/about --warnings         # one page, with warnings
```

It fetches the front page, the newest published pages of every bundle with SEO, every public
Views page and a not-found page, then checks each one's JSON-LD: every `{"@id"}` reference must
resolve inside its graph, and `@adobe/structured-data-validator` applies schema.org and Google
rich-result rules. It exits non-zero on any error. `--insecure` skips TLS checks for local sites
with self-signed certificates. The schema.org vocabulary is downloaded to the temporary directory
and refreshed monthly.

The reference check is tested on its own: `node --test tools/validate/lib.test.mjs`.
