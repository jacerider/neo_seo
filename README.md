# Neo | SEO

A connected schema.org graph, head tags and on-page SEO for Neo sites. It replaces `metatag`,
`schema_metatag` and `neo_metatag`.

- **Schema graph** — one JSON-LD `@graph` per page, assembled from **graph pieces** (plugins), with
  every `@id` generated from Yoast's conventions so the pieces link to each other.
- **Head tags** — a curated, plugin-extensible set fed by token templates and per-page overrides.
- **SEO panel** (`neo_seo_panel`) — Google and social previews in the edit form and in Alchemist.
- **Analysis** (`neo_seo_analysis`) — focus-keyphrase and readability checks.

Under construction. The design lives in the wps site's `.scratch/neo-seo/spec.md`.
