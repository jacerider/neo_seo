# Neo | SEO

Search and sharing for Neo sites: a connected schema.org graph, the head tags search engines and
social networks read, per-page overrides with live previews, and content analysis in the editor.
It replaces `metatag`, `schema_metatag` and `neo_metatag`.

- [What it does](#what-it-does)
- [Requirements and install](#requirements-and-install)
- [Setting up a site](#setting-up-a-site)
- [The schema graph](#the-schema-graph)
- [Head tags and default templates](#head-tags-and-default-templates)
- [Per-page overrides](#per-page-overrides)
- [Bundle mapping](#bundle-mapping)
- [Views pages](#views-pages)
- [Components that add to the graph](#components-that-add-to-the-graph)
- [The SEO panel](#the-seo-panel-neo_seo_panel)
- [Content analysis](#content-analysis-neo_seo_analysis)
- [Validating structured data](#validating-structured-data)
- [Moving a site off metatag](#moving-a-site-off-metatag)
- [Extending](#extending)
- [Permissions, routes and settings](#permissions-routes-and-settings)
- [What it does not do](#what-it-does-not-do)
- [Tests](#tests)

## What it does

| | |
|---|---|
| **Schema graph** | One JSON-LD `@graph` per page: the site's Organization (or Person), WebSite, WebPage, primary image, breadcrumb, Article, authors and profile people, plus anything a module or component adds. Every piece links to the others by `@id`, so search engines read one connected description of the page rather than unrelated fragments. |
| **Head tags** | Title, description, canonical, robots, Open Graph, X card and site verification codes, from token templates layered global → entity type → bundle → front/403/404. |
| **Per-page overrides** | Editors can set a page's search title, description, share image, page type, article type, noindex, focus keyphrase and cornerstone flag. These are stored on the entity, revisioned and translatable. |
| **SEO panel** | Google desktop and mobile previews, a Facebook/LinkedIn card and an X card, with live length and pixel-width feedback. Shown in the edit form sidebar and inside the Alchemist editor, where SEO edits are drafted with the layout. |
| **Content analysis** | Yoast-style focus-keyphrase and readability checks run against the page as it will render, scored with traffic lights. Scores are advisory. |
| **Tooling** | `drush neo-seo:validate` checks pages against schema.org and Google's rich-result rules. `drush neo-seo:migrate` converts a metatag setup and diffs the head before and after. |

The package has three modules:

- **`neo_seo`** — the graph, head tags, templates, overrides, bundle mapping, Views pages, component
  pieces, draft rendering and the Drush commands. A site can run on this alone.
- **`neo_seo_panel`** — the SEO panel in the edit form and the Alchemist editor.
- **`neo_seo_analysis`** — content analysis inside the panel.

## Requirements and install

- Drupal 11.3+, `token`, `media`, `jacerider/neo`, `jacerider/neo_settings`; `neo_seo_analysis` also
  needs `jacerider/neo_tooltip`, whose tooltips carry its help.
- `neo_alchemist` is optional, at 1.3 or later (Composer refuses an older one). With it, components
  can add to the graph and the SEO panel appears in the Alchemist editor.
- `views` is optional. With it, Views pages get their own SEO settings.
- Node 20+ wherever `drush neo-seo:validate` runs (the DDEV web container has it).

```bash
ddev composer require jacerider/neo_seo
ddev drush en neo_seo neo_seo_panel neo_seo_analysis
ddev npm run build:back        # the panel and analysis are back-scope assets
```

Installing never overwrites settings a site already has. The shipped default templates are optional
config, so they only install where none exist.

**While `metatag` is still enabled**, neo_seo prints its graph but leaves the head tags to metatag,
so a page never gets two titles. Turn on *Print head tags while metatag is enabled* in the settings
only to compare the two side by side. See [Moving a site off metatag](#moving-a-site-off-metatag).

## Setting up a site

Settings live at **Configuration → Neo → SEO** (`/admin/config/neo/seo`).

1. **Site identity.** Say whether the site represents an organization or a person.
   - Pick an organization type: Organization, Corporation, Professional service, Local business, NGO,
     and others.
   - Fill in the name, description, logo, social profiles, telephone, email and postal address.
   - **Logo:** an image media item, or, with `neo_favicon`, *the site favicon*: its largest icon, at
     its own size (never cropped to a share card). Until a favicon is uploaded there is no logo, and
     the form says so.
   - **Social profiles:** with `neo_site_settings`, *Use the social links from site settings* takes
     every filled link field of a site settings type (`social` by default), in its display order,
     and stays current as they change. *Other social profiles* (one URL per line) adds any it does
     not hold; a profile in both is listed once.
   - Both are on by default where the module is there and neo_seo has nothing of its own: on
     install, on update, and when either module is installed later. A typed profile list is only
     replaced when site settings already holds every URL in it.
   - Every text field accepts tokens. A site that keeps its phone and address in `neo_site_settings`
     can point at `[site:phone]` and `[site:address:*]`.
2. **Per-page SEO.** Tick the content that gets per-page overrides (usually *Content* and
   *Taxonomy term*). This adds a field to those entity types, and turning one off is refused while
   it holds data.
3. **Share image style.** Choose the image style share images and the graph's primary images are
   served through. The default is `neo_social`, 1200×630. Its dimensions are what the head tags
   report.
4. **Verification.** Paste the content value of each search engine's verification meta tag. They
   are printed on the front page only.
5. **Default templates.** Use the *Default templates* tab (`/admin/config/neo/seo/defaults`). See
   [Head tags and default templates](#head-tags-and-default-templates).
6. **Bundle mapping.** Go to each content type's or vocabulary's edit form and open its **SEO**
   section. See [Bundle mapping](#bundle-mapping).

Then check the result with `ddev drush neo-seo:validate --insecure`.

## The schema graph

Every front-end page prints one `<script type="application/ld+json" class="neo-seo-graph">`
holding one `@graph`. Admin pages and the Alchemist editor's own routes get none.

The pieces, and when each appears:

| Piece | `@id` | When |
|---|---|---|
| Site identity (Organization type or Person) | `{site}/#organization` | Every page |
| Logo ImageObject | `{site}/#/schema/logo/image/` | When site identity has a logo |
| WebSite | `{site}/#website` | Every page; `publisher` → site identity |
| WebPage (+ page type) | the canonical URL | Every page; `isPartOf` → WebSite; `about` → site identity on the front page |
| Primary ImageObject | `{canonical}#primaryimage` | When the page has an image |
| BreadcrumbList | `{canonical}#breadcrumb` | Every page but the front page, built from the site's breadcrumb |
| Article (+ article type) | `{canonical}#article` | When the bundle or editor makes the page an article |
| Person | `{site}/#/schema/person/{uuid}` | The subject of a profile page, and each article author |
| Component nodes | as declared, else `{canonical}#{type}-{uuid}` (`-{row}` when repeated) | When a component on the page declares them (`seo` in its `.component.yml`) |

The `@id` suffixes are the ones Yoast uses, so tools that know Yoast's graph read this one too. A
person keeps the same `@id` on their own profile page and as an author everywhere else, because the
id is built from the entity's uuid, not its URL.

**One image, everywhere.** A page's share image (`og:image`, `twitter:image`) and its primary image in
the graph are always the same image. It is taken from the first of:

1. the editor's override;
2. the bundle's mapped image field;
3. the image template (`[neo:image]` by default).

Empty values are never emitted. A property with nothing in it is left out rather than printed
empty. The sitelinks search box (`SearchAction`) is not emitted, because Google retired it.

## Head tags and default templates

neo_seo prints these tags:

- the document **title**;
- **description**;
- **canonical**, which is always the WebPage `@id` and `og:url`;
- **robots**, only when it has a value;
- **Open Graph**: site name, type, URL, title, description, locale, image with width, height and
  alt, and article published and modified times;
- the **X card**: `summary_large_image` when there is an image;
- **verification codes**.

It removes core's duplicate canonical and shortlink and the Generator tag.

The values come from **default templates**: token strings for each field (title, description,
social title, Open Graph type, share image, robots), kept in sets that apply to:

| Set | Applies to |
|---|---|
| `global` | Every page |
| an entity type, e.g. `node` | Every page of that type |
| a bundle, e.g. `node__article` | Every page of that bundle |
| `front`, `403`, `404` | Those pages, on top of everything else |

**The more specific set wins field by field, and only where it has a value.** A bundle set that only
sets a title keeps every other field from the sets below it.

Front, 403 and 404 sit on top of the chain rather than replacing it. Under metatag, a front-page set
throws away everything the global and bundle sets say; here it doesn't.

The shipped sets are:

- `global`: title `[neo:title] | [site:name]`, description `[neo:description]`, image `[neo:image]`.
- `front`: title `[site:name]`.
- `403` and `404`: `noindex, follow`.

Templates resolve to plain text. When a token resolves to nothing, its separator goes with it: a
title template on a page without that token prints `Site name`, not `| Site name`.

## Per-page overrides

On the entity types turned on in settings, the edit form sidebar gets two sections, both closed
to start: **Search & social** (how the page is listed and shared) and **SEO** (the focus keyphrase
and cornerstone toggle, which content analysis reads). With `neo_seo_analysis` a third,
**Readability**, follows. A form without a sidebar shows them where the field sits. They hold:

| Field | Effect |
|---|---|
| Title, Description | Replace the templates for this page. Tokens are allowed. |
| Share image | An image media item, chosen in the media library (an autocomplete without `media_library`); replaces the mapped field and the template. Empty, the panel shows the image shared automatically. |
| Page type, Article type | Choose from the types the bundle allows. A type the bundle does not allow is ignored. |
| Hide from search results | Prints `robots: noindex, follow`. It does not remove the page from a sitemap. |
| Focus keyphrase, Cornerstone | Used by content analysis. In the SEO section. |

The controls keep one set of names (`neo_seo[0][title]` and so on) whichever section shows them, so
a module adding a section of its own (`#neo_seo_sections` on the widget element, and
`#neo_seo_section` on what goes in it) changes nothing that is saved.

Overrides are stored in one base field, `neo_seo`. They are translatable and revisioned with the
entity, and also available as tokens: `[neo-seo:title]`, `[neo-seo:description]`,
`[neo-seo:keyphrase]`, `[neo-seo:page-type]`, `[neo-seo:article-type]`.

Editing them takes the *Edit SEO overrides* permission.

## Bundle mapping

Each content type, vocabulary and media type form has an **SEO** section. The mapping is stored on
the bundle as third-party settings, so it is exported with the bundle.

| Setting | Meaning |
|---|---|
| Page type | WebPage, About page, Profile page, Contact page, Collection page, Item page, and others. |
| Page types editors may choose instead | The other page types offered in the override. |
| Article type | None, Article, Blog post, News article, Report, Technical article, and others. News, posts and reports are articles; most other pages are not. |
| Article types editors may choose instead | The other article types offered in the override. |
| Primary image | An image or media reference field. It feeds the share image and the primary image. |
| Date published / Date modified | Timestamp or date fields; by default *created* and *changed*. A date-only field is published as a date, never an invented time. |
| Authors | A reference to content whose own type is a **Profile page**. Each referenced, visible entity becomes an author of this page's Article. |
| Job title, Social profiles | On a profile-page bundle: the fields for the Person's job title and `sameAs` links. |

**People.** Any bundle mapped as a *Profile page* describes a person. Their page gets a Person as
the WebPage's `mainEntity`. When another page names them in its Authors field, the same Person
appears as that page's `author`. Unpublished or inaccessible people are left out.

An article type also sets `og:type` to `article`, and a profile page sets it to `profile`, whatever
the template says.

A bundle config type other than node types, vocabularies and media types needs one line of config
schema before the section appears:
`{config prefix}.*.third_party.neo_seo: { type: neo_seo.bundle_mapping }`.

## Views pages

On sites with Views, every page display gets an **SEO** setting in the view's *Page settings*. It
holds title, description and share image templates, plus a page type, e.g. *Collection page* for a
listing or *Search results page* for a search.

The templates can use `[view:*]` tokens, contextual arguments included. They sit above the global
set and below front, 403 and 404. They are stored in the view's own config.

## Components that add to the graph

With `neo_alchemist`, a component adds nodes to the page's graph by declaring them in its
`.component.yml`, under a top-level `seo` key. No PHP is needed. The declaration is shaped like
the JSON-LD it produces:

```yaml
seo:
  - '@type': ProfessionalService
    '@each': locations as office
    '@id': '#office-{{ office.title|slug }}'
    '@required': [name, address]
    name: '{{ office.title }}'
    address: '{{ office.address|postal_address }}'
    hasMap: '{{ office.directions|url }}'
    geo:
      '@type': GeoCoordinates
      '@if': '{{ office.latitude }}'
      latitude: '{{ office.latitude }}'
      longitude: '{{ office.longitude }}'
    parentOrganization: '{{ graph.organization }}'
```

- **Values:** `{{ path }}` reads a prop, a row, or a reserved root (`page.url|title|language`,
  `site.name|url`, and `graph.organization|website|page`, which print a reference); anything else
  is literal. A value that is exactly one placeholder keeps its type; placeholders in text are
  filled in as text. Props are read as resolved, so bindings to entity fields work.
- **Directives:** `@each: <list> as <name>` repeats a node, or builds a list inside a property, at
  any depth. `@if: '{{ path }}'` (or `'not {{ path }}'`) keeps a node only when the value is
  truthy. `@required` drops a node missing the properties it lists. `@id` is optional; a
  `#fragment` is page-scoped, and a declared id two nodes share takes `-2`.
- **Filters:** `postal_address`, `url`, `image`, `text`, `date`, `slug`, `first`, `join('sep')`.
- **What is skipped:** unpublished instances, ones the visitor cannot see, empty values, and
  objects left with only their `@type`.
- **VideoObject checks:** a VideoObject must have a name, a thumbnail, an upload date and either a
  content or an embed URL. The upload date falls back to the page's published date and the name to
  the page title. A node still missing something is left out and logged once, never emitted
  invalid.
- **While building a component:** `drush neo:alchemist:validate` lints the declaration (syntax,
  paths, filters), `drush neo-seo:component <id>` prints the JSON-LD the examples produce, and the
  component audit reports a node a page would leave out. The preview page's audit badge shows the
  same JSON-LD.

The neo-component skill has the full reference.

## The schemamap: the whole site's schema

`/schemamap.xml` lists the site's schema as feeds, in the format Yoast SEO's schema
aggregation uses, which the Yoast schema visualizer and NLWeb agents read. With it the
visualizer shows the whole site's graph, not one page's.

- **The schemamap** is a sitemap `urlset`: one `<url contentType="structuredData/schema.org">`
  per feed page, with the bundle's last change as `lastmod`.
- **A feed**, `/neo-seo/schema/{entity_type}/{bundle}/{page}`, holds the graphs of up to 100
  published entities of a bundle with per-page SEO, one JSON-LD node per line, each with its
  `@context`. A node many pages share (the organization, the website) is listed once.
- **What is left out:** what an anonymous visitor may not see, a page kept out of search
  results, and a bundle whose robots default is `noindex`. Views pages are not listed.
- **Built** as an anonymous visitor, from each entity's page as the head and graph would print
  it. Each entity's nodes are cached and invalidated by what they were built from, per site
  address (the @ids are absolute URLs); the feed responses are cacheable like any page.
- **robots.txt:** with the robotstxt module, a `Schemamap:` directive is added. A static
  robots.txt takes it by hand: `Schemamap: https://example.com/schemamap.xml`. It is the
  [Schema Feeds](https://github.com/nlweb-ai/website/blob/main/SCHEMA_SPEC.md) directive, which
  the Yoast visualizer and NLWeb agents look for, not `Sitemap:`. A host that serves its own
  robots.txt (Pantheon on `*.pantheonsite.io`) hides it; give the visualizer
  `/schemamap.xml` itself there.
- **Turning it off:** *Publish a schemamap* in the settings (on by default; `neo_seo_update_11002`
  turns it on for existing sites). The routes then answer 404.

```bash
ddev drush neo-seo:schemamap           # the feeds /schemamap.xml lists
ddev drush neo-seo:schemamap --warm    # build and cache every feed, e.g. after a deploy
drush --uri=https://example.com neo-seo:schemamap --warm   # on a host, at the address visitors use
```

A feed built cold resolves every page's layout, about a second for a hundred simple pages and
more for pages that list many things; warmed, it is served from the cache. The cache is kept per
site address, so warm with the `--uri` visitors use: Drush's default is often `http://`, and
warming that fills a cache an `https://` site never reads.

## The SEO panel (`neo_seo_panel`)

Enabling the module adds previews and feedback to the Search & social section. Nothing in any form
display changes, and uninstalling puts the plain sections back.

- **Previews:** Google desktop, Google mobile (with favicon and breadcrumb-style URL),
  Facebook/LinkedIn and X. They are drawn at each network's real width and scaled to fit.
- **Live feedback:** title and description lengths are measured in characters and in pixels against
  Google's cut-off: 600px for a title, 920px for a description.
- **What the previews show:** the head the page will print, not the raw field. They are refreshed
  from a **draft render**, the page built from the form's current values without saving, so tokens
  and templates resolve exactly as they will on the live page.
- **Help:** every SEO field carries plain-language help as its description: a one-line hint, then
  what it is for. Shown the way the site shows descriptions: behind a "?" badge where neo_tooltip
  makes them tooltips, under the field where it does not.
- **Suggested description:** while the description comes from the template, a suggestion sits under
  the field: whole sentences from the page's first substantial paragraph (a sentence holding the
  focus keyphrase preferred), as many as fit Google's 920px, cut only at a word. *Use this* copies
  it into the field to edit; nothing is applied on its own. Extractive only, no AI. Tests:
  `node --test 'modules/neo_seo_panel/tests/js/*.test.ts'`.

In the **Alchemist editor** the same panel opens from the editor's bottom bar as **Search &
social** (not "SEO", which is the score in the top bar). With `neo_seo_analysis` a *Scores* row at
its top shows the two scores, each opening its checks. SEO edits there are **drafted with the
layout**:

- they reach the page only when the layout is published;
- Revert and Reset discard them;
- the live page is untouched until then.

The editor keeps one draft render from the moment it loads, refreshed after every layout or SEO
draft change, which the panel's previews (and the scores below) read.

## Content analysis (`neo_seo_analysis`)

Adds content analysis to the panel:

- **Layout, everywhere:** one column. A score's header — a ring with the score, its name and what it
  was measured against, and for SEO the focus keyphrase and cornerstone fields — above its checks,
  grouped *Problems* then *Improvements*, each heading with its severity icon (red disc, amber
  triangle) and count, then one folded *N passed* row (a green tick); for SEO the heading outline
  is the last row. The heading and image alt text checks sit in the SEO list, tagged *Not scored*.
  A check that can be fixed is a row-wide button to its fix, "Fix ›" at its end; *Show in preview*
  is an eye beside it.
- **Edit form:** the SEO header and checks in the **SEO** section, the readability ones in a
  **Readability** section. A page built in its own Alchemist layout (a component tree that allows a custom layout,
  rendered by the page's full view mode) is written in the layout editor, where its checks can be
  acted on: its form has no Readability section, and the SEO section shows the two scores, live,
  with **Open in the layout editor**, which opens the editor on its SEO checks
  (`?neo_seo=seo`) and asks first when the form holds unsaved changes. A page whose layout is
  locked is fed by the form's own fields and keeps the checks.
- **Alchemist editor:** two score pills in the editor's top bar, a small ring then `SEO 80` and
  `Read 64` (green 70+, orange 40–69, red below), scored from editor load and dimmed while they
  update. Without a keyphrase the SEO score is the partial one, as the dropdown shows it; its
  tooltip says the keyphrase checks are not in it, and the pill opens on the keyphrase field.
  Clicking a pill opens its view of a 520px dropdown. *Edit search & social* opens the panel.
- **Everywhere:** the *N passed* row, and the heading outline (open on its own when the outline has
  a problem), remember whether they were open in the browser. What each score means is behind the
  "?" by its name.
- **Save confirmation (Alchemist):** the Save dialog shows the same two scores, the top three
  problems and a warning when the page is set to hide from search (drafted or saved). Text only and
  advice only: the dialog saves, cancels and focuses exactly as before, and nothing shows until the
  first analysis has run.

- **SEO checks** against one focus keyphrase: the keyphrase in the title, description, URL, H1,
  introduction, subheadings and image alt text; keyphrase density; title and description length;
  text length; internal and outbound links; and whether the keyphrase is already used on another
  page.
- **Readability** (English only): Flesch reading ease, sentence and paragraph length, subheading
  spacing, passive voice, transition words, and repeated sentence openings. Other languages get the
  SEO checks only.
- **Cornerstone** pages are held to stricter thresholds.
- **Where it runs:** in a Web Worker, against the draft render. Typing does not freeze the form.
- **Show in preview:** in Alchemist, the eye on a check marks its text in the preview.

Scores are stored with the overrides when the page is saved or published. The admin content list
can show *SEO* and *Readability* columns (Views handler `neo_seo_score`; a page that was never
analysed shows "–") and filter by *Cornerstone*. **Scores never block a save.**

The engine is original TypeScript written from Yoast's published descriptions of its free checks.
No Yoast code is used. See `modules/neo_seo_analysis/README.md` for the engine's contract and
thresholds.

## Validating structured data

```bash
ddev exec npm ci --prefix web/modules/contrib/neo_seo/tools/validate   # once per environment
ddev drush neo-seo:component front:locations                        # what a component declares
ddev drush neo-seo:validate --insecure                                 # a sample of every kind of page
ddev drush neo-seo:validate --insecure --url=/about --warnings         # one page, with warnings
```

It fetches pages over HTTP:

- the front page;
- the newest published pages of every bundle with SEO (`--per-bundle`, default 2);
- every public Views page;
- a page that does not exist.

Each page's JSON-LD is then checked two ways:

1. every `{"@id"}` reference must resolve inside its own graph;
2. `@adobe/structured-data-validator` applies schema.org's rules and Google's rich-result rules.

The command exits non-zero on any error, so it can gate a deploy. `--insecure` skips TLS checks for
local sites with self-signed certificates. The schema.org vocabulary is downloaded to the temporary
directory and refreshed monthly.

## Moving a site off metatag

```bash
ddev drush neo-seo:migrate --dry-run --insecure --report=../seo-diff.md
```

The migration does three things, and never overwrites a value neo_seo already has:

- **metatag defaults → default templates.** The set ids match: `global`, `front`, `node`,
  `node__article` and so on.
- **schema_metatag → bundle mappings and site identity.** A logo of `[neo:logo]` becomes *the site
  favicon* (with `neo_favicon`); social profiles that site settings already holds turn its links on
  instead of being copied.
- **metatag field values → per-page overrides.**

Every metatag value is reported as one of:

- *converted*;
- *derived* — neo_seo works it out itself;
- *dropped* on purpose;
- *unconverted* — someone has to decide. Typically that means a logo to pick, or a specialist
  schema type that needs a graph piece in code.

It then fetches a sample of pages twice — as visitors see them, and as neo_seo will print them
(through a short-lived, uncacheable diff mode) — and writes a Markdown report comparing head tags and
graph nodes page by page.

`--dry-run` keeps the configuration only for as long as the diff takes, then restores it exactly,
and copies no content. Even so, run it on a copy of the site, because for those seconds the
converted configuration is live.

Nothing is uninstalled. Once the report reads right:

1. Run without `--dry-run`.
2. Uninstall `neo_metatag`, `schema_metatag` and its submodules, and `metatag`.
3. Remove the metatag fields.
4. Validate.
5. Check `git status config/` before exporting.

## Extending

### A graph piece

Add a plugin under `src/Plugin/GraphPiece/` in any module. Use it for specialist types such as
JobPosting, Event or Product, which a site's own module understands better than a generic mapping
could:

```php
#[GraphPiece(id: 'my_event', label: new TranslatableMarkup('Event'), weight: -20)]
class Event extends GraphPieceBase {

  public function isNeeded(PageContext $context): bool {
    return $context->entity?->bundle() === 'event';
  }

  public function generate(PageContext $context): array {
    $values = $this->headValues->resolve($context);
    return [[
      '@type' => 'Event',
      '@id' => $this->ids->pageScoped($context, 'event'),
      'name' => $context->title,
      'description' => $values->get('description'),
      'organizer' => SchemaIds::ref($this->ids->siteIdentity($context)),
      'mainEntityOfPage' => SchemaIds::ref($this->ids->webPage($context)),
    ]];
  }

}
```

- Build every `@id` through `SchemaIds`, never by hand. That is what guarantees references land on
  real nodes.
- Read the page's title, description, image, types and dates from `$this->headValues`, so the graph
  and the head tags agree.
- Record anything you read with `$context->addCacheableDependency()`.
- Empty values are pruned for you.

`hook_neo_seo_graph_alter(array &$nodes, PageContext $context)` sees the whole graph last.
`hook_neo_seo_graph_piece_info_alter()` alters or removes pieces. See `neo_seo.api.php`.

### A head tag

Add a plugin under `src/Plugin/HeadTag/` extending `HeadTagBase`. Its `build(HeadValues $values)`
returns `html_head` attachments; `$this->meta($name, $content)` builds a meta tag. A tag that
declares `fields:` in its attribute gets those fields in every default template set and in the
template form.

### The SEO panel

`hook_neo_seo_panel_alter(array &$panel, ContentEntityInterface $entity, string $host)` adds
libraries and config to the panel. `Drupal.neoSeoPanel.extend()` hooks into it in the browser.
`hook_neo_seo_panel_badge_alter(array &$badge, ContentEntityInterface $entity)` fills the panel's
badge in the Alchemist top bar (empty: no badge), and `Drupal.neoSeoPanel.extendBadge()` keeps it
current from editor load. Content analysis is built both ways. See
`modules/neo_seo_panel/neo_seo_panel.api.php`.

## Permissions, routes and settings

| Permission | Allows |
|---|---|
| `administer neo_seo` | Settings, default templates, bundle mapping. |
| `edit neo_seo overrides` | Changing a page's per-page SEO; the keyphrases list. |

| Route | |
|---|---|
| `/admin/config/neo/seo` | Settings |
| `/admin/config/neo/seo/defaults` | Default templates |
| `GET /neo-seo/draft/{entity_type}/{id}` | A page's draft render as JSON, with its Alchemist drafts applied (update access). Used by the panel. |
| `GET /neo-seo/keyphrases` | Other pages' focus keyphrases, for analysis (`edit neo_seo overrides`). |
| `GET /schemamap.xml` | The schemamap (public; 404 when turned off). |
| `GET /neo-seo/schema/{entity_type}/{bundle}/{page}` | A schemamap feed, one JSON-LD node per line (public). |

| Config | |
|---|---|
| `neo_seo.settings` | Site identity, verification codes, per-page entity types, share image style, *print alongside metatag*, *publish a schemamap*. |
| `neo_seo.defaults.*` | Default template sets. |
| `{bundle config}.third_party.neo_seo` | Bundle mapping. |
| `views.view.*` display extender `neo_seo` | Views page settings. |

## What it does not do

- **Sitemaps, redirects, URL aliases, robots.txt and breadcrumb building** stay with their own
  modules (`simple_sitemap`, `redirect`, `pathauto` and the breadcrumb builders). neo_seo reads the
  breadcrumb; it does not make one.
- **hreflang** is left to core and content translation.
- **FAQ and HowTo.** Google no longer shows either rich result, so neo_seo offers neither.
- **More than one focus keyphrase, word forms, synonyms, and readability in languages other than
  English.**

## Tests

```bash
ddev phpunit web/modules/contrib/neo_seo/tests                          # kernel and unit
node --test 'modules/neo_seo_analysis/tests/js/*.test.ts'               # analysis engine (package root)
node --test tools/validate/lib.test.mjs                                 # graph reference check
```
