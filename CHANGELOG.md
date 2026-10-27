# Changelog

## The SEO and Read scores open their analysis again

**In the Alchemist editor, clicking SEO or Read opens that score's analysis straight away. The
"Search & social" button in front of them opens the panel.** The scores used to open the panel,
and the analysis took a second click from there.

- The scores' tooltips are neo_tooltips, like the rest of the editor's header: the score and its
  band, or "SEO score" / "Readability score" until the analysis has run. They render on the page
  body, so hovering Read no longer squares off the group's right end.
- "Edit search & social" and the field fixes in the analysis open the panel again. They looked for
  a panel hidden with `display: none` and for a header button the panel no longer had, so they
  did nothing.

## The SEO scores no longer keep a layout draft alive

**Undoing every layout change in the Alchemist editor now leaves the page with no draft, even
with the analysis running.** The SEO and readability scores are drafted beside any layout draft,
which made the editor count the draft as holding a Search & social edit. The panel now says its
scores alone are not an edit (`SeoPanel::isEdited()`), so Undo back to the saved layout drops
them with the draft. A drafted override (a title, a description, the slug) still keeps the draft.

## The Alchemist panel matches the inspector, and its URL slug is one line

**Search & social in the Alchemist editor read differently from the component inspector next
to it.** Its field labels were 14px bold against the inspector's 13px semibold, and the URL slug
showed its address ("/") and the slug on two lines inside one box. In the panel, labels and
headings now use the inspector's 13px semibold, help and readouts its 12px. The slug is one line
with the address inline before it, muted (content colour at 75%, over 4.5:1) and trimmed from its
start when long. The edit form's slug field is unchanged. CSS only; editing the slug still saves
through the panel draft.

## A product with variations is a ProductGroup

**A product sold in several variations is now described in Google's variant form, so search
results can show its variants.** With more than one active variation, the product page's
`mainEntity` is a **ProductGroup**: `productGroupID` (the product id), the product's name,
description, brand and image, `variesBy`, and `hasVariant` — each active variation a Product with
its own `sku`, `name` (the variation title), `image` where it has one, `?v=` URL and Offer.
`variesBy` lists the properties Google reads variants by (color, size, material, pattern, age,
gender) for the attributes whose values differ between the variations. An attribute counts when
its machine name or label is one of those (`colour` and `age group` too); age and gender go on the
variant's `audience`, Google's age groups as age ranges. Any other attribute (a finish, a pack
size) tells variants apart only by their names. A single variation's matched attributes are on its
Product too.

## A product page describes its product: Product and Offer

**A product page's schema said only that it was a page.** With `neo_seo_commerce`, a product with
one active variation is a **Product**, the WebPage's `mainEntity`: its `name`, `description`,
`sku`, `url`, `brand` (from the product type's brand field, left out when there is none) and
`image` — the editor's share image or the product type's mapped image field, else the
variation's own image field. The site-wide image template never stands in for a product photo,
so a product with no image of its own has none, and the validator says so. Its **Offer** carries
what an anonymous visitor pays in the default store, resolved by Commerce's price resolvers so
sales and price lists show as on the page (a signed-in customer's own price never does), the
currency, `InStock` (or the stock level, where `commerce_stock` is installed), the variation's
`?v=` URL and the site identity as `seller`. Unpublished variations are never offered, and a
product with none published has no Product. The graph is the same for every visitor and is
cached on the product, its variations, the store and the site's promotions and price lists.

## Product pages get SEO like content: `neo_seo_commerce`

**A Commerce product page had no per-page SEO and no bundle mapping, so it was a plain WebPage
that editors could not tune.** The new `neo_seo_commerce` submodule (for Commerce sites only;
neo_seo itself still has no Commerce code) gives product types the bundle mapping content types
have: their edit form gets the **SEO** and **URL** sections. Installing it adds products to the
per-page entity types, so products get the SEO overrides field, the previews and analysis, and
the URL field where a pathauto pattern builds product addresses. Every product type becomes an
*Item page*, and so does a product type made later; a type that arrives by config import keeps
its own. The SEO section has one more setting on product types, **Brand**: a taxonomy term
reference or text field on the product. Uninstalling removes the product types' mapping, whose
config schema lives in the submodule, and leaves products in the per-page entity types for the
site to turn off.

## A module can set the head of a page the templates cannot reach

**`hook_neo_seo_head_templates_alter()`** changes a page's head templates and their token
data before the tokens are replaced, after the default layers and the editor's overrides. A
custom route that describes an entity without being its canonical page (a restaurant's
hours page, an event's tickets page) had no way to get a title or description from that
entity: the layers only know canonical entity pages. Now its module sets the templates and
adds the entity to the token data.

## Each page of a paged listing names itself canonical

**Page 2 of a listing printed page 1's address as its canonical URL, which tells search engines
that page 2's items are on page 1.** Past the first page, the canonical URL keeps the `page` query
(`/news?page=1`) and drops every other query argument. So do `og:url`, the WebPage `@id` and the ids
hung off it, including the ItemList's. The first page, `?page=0` included, is still the bare
address. Head tags and the graph now vary by `url.query_args:page`.

## A collection page lists its items as an ItemList

**A listing page was a CollectionPage that listed nothing, so search engines could not tell what it
collects.** A page whose page type is *Collection page* now has an ItemList as its WebPage's
`mainEntity`: a `ListItem` per row on the page being viewed, with its `position` and the `url` of
the row's own page (Google's summary-page form). Rows without a page of their own are skipped, and
positions follow the pager: page 2 of a ten-per-page listing starts at 11. On a Views page the rows
are its display's; on an entity's page, those of a view a component on the page lists through
neo_alchemist's *Views* value provider (the first with a pager, else the first). Either way they are
read whether or not the page's render came from the cache. To make an entity's page a collection,
set its bundle's page type to *Collection page*, or allow that type as an editor's choice and set it
on the page. `drush neo-seo:validate` no longer reports a ListItem of an ItemList without a name as
an error: Google's summary form gives it none. A BreadcrumbList's items still need their names.

## A component's `seo` declaration numbers its rows: `loop.position`

**A component could not declare an ItemList: Google requires a `position` on each ListItem, and
`@each` gave a row no number.** Inside an `@each`, `{{ loop.position }}` is the row's place, from 1,
among the nodes that `@each` prints: a row left out (by `@if`, `@required`, or for being empty)
takes no position, so the positions run 1, 2, 3; a nested `@each` numbers its own rows. `loop` is
now a reserved root like `graph`, `page` and `site`, so the linter refuses a prop or row named
`loop`, and reports `loop.position` read outside an `@each`. The README's *Components that add to
the graph* has an ItemList example.

## The slug check wants the keyphrase at the front

**The keyphrase-in-slug check passed a slug that held the keyphrase anywhere, in any order.** It is
now *good* only when the keyphrase's words lead the slug, in order (function words such as "to",
which pathauto drops from slugs, are skipped), and *ok* — "Move it to the front" — when they are all
there but later. A slug missing words is scored as before. The title check already wanted the
keyphrase at the start.

## Editors change a page's URL slug, or give it a URL of its own

**Changing a URL meant unticking pathauto and retyping the whole path, and it could not be done in
Alchemist.** A URL field now has a *URL* section of its own in the edit form's sidebar, where core's
URL alias section was, and sits under the Alchemist SEO panel's previews, above the title, under a
*URL* heading: the order a search result shows them. Icons beside it open the saved page in a new
tab and copy its address. An *Automatic | Custom* choice leads it: Automatic shows *URL slug*, the
page's part of its address; Custom shows *Custom URL*, a whole address of its own. An editor who may
not set a custom URL sees no choice, or, on a page that has one, sees it read-only and may choose
Automatic to hand the page back to its pattern. Put `[neo:slug]` in a pathauto pattern where the
title was, and `[neo:parents:join-path]` where core's parents path was: editors change just their
page's part, and a parent's slug carries into its children's addresses. Without slugs both give
exactly today's values, so no existing alias changes. When a page's slug or title changes, or its
menu link is renamed or moved, the pages under it are re-aliased (pathauto never did this for menu
children), and the URL field says how many will move. `drush neo-seo:slugs` moves a site's patterns
onto the tokens: it shows the change and which pages would move, then asks, and offers to disable
core's *URL alias* field in the form displays where neo_seo's URL field takes its place. A bundle's
new URL section has *Editors may set a custom URL* (off by default) for replacing the whole address,
and lists the bundle's patterns and the URL tokens each uses. On managed bundles the field replaces
core's path widget, whose *URL alias* row in Manage form display is then not used. Who may change a
page's URL is who may create URL aliases, as for core's widget, whether or not they may edit the SEO
overrides; in Alchemist the field is in the SEO panel, so it also takes the panel's access. In
Alchemist, URL edits are drafted with the layout and applied on Publish. Run `drush updatedb`
(`neo_seo_update_11004`): it adds the `slug` column to the SEO overrides; nothing else changes until
a pattern uses the tokens.

The slug sits under the rest of its address, in one box: the pattern's part, muted and wrapping
however deep the page is, then the input. An empty slug shows the slug its title gives, and follows
a new title before it is saved. As editors type, spaces become hyphens (pathauto's separator) and
letters lowercase, in both inputs; nothing typed is refused. Leaving the input replaces what was
typed with the slug as it will be saved ("Phase 2: The Plan!" becomes `phase-2-plan`); where that
address is another page's, the slug stays clean and a note names the `-0` address pathauto will use.
A custom URL is cleaned the same way, segment by segment ("/About Us/Team!" becomes
`/about-us/team`), on leaving the input and again on save; a page's existing custom URL is left as
it is. Where saving moves a page — a new slug, a new custom URL, or a new title with no slug — one
callout shows the old address and the new as a diff (a long one cut in the middle), why when the
title moved it, the `-0` when the slug was taken, whether a published page's old address will
redirect (with the redirect module's automatic redirects) or its links will stop working, and the
pages under it that move too; it is a warning when links will break, and *Keep current address*
undoes the move. Other notes are callouts of their own, toned as errors, warnings or information.
Where a bundle's pattern has no slug, the address shows read-only, set by the site. The previews and
the keyphrase-in-slug check use the address the page will have.

## Local businesses by their specific type, with opening hours

- The organization type offers LocalBusiness subtypes, grouped apart from the other organizations:
  HVAC business, plumber, electrician, roofing, general contractor, house painter, locksmith,
  moving company, auto repair, dentist, legal, accounting and real estate. Search engines read the
  kind of business from `@type`, and Google asks for the most specific LocalBusiness subtype.
- A local business can publish its weekly opening hours (`openingHoursSpecification`) from an
  office hours field in site settings, chosen under Site identity. Days with the same hours share
  one specification. Off by default; nothing changes on an existing site until a field is chosen.
- `neo-seo:migrate` turns schema_metatag's additional type into the organization type when it is
  one neo_seo knows (an `Organization` with additional type `HVACBusiness` becomes an
  `HVACBusiness`).

## Titles and descriptions are edited with snippet variables

**Tokens had to be typed by hand, and an empty title hid the template it fell back to.** The title,
description and social title templates, and a page's own title and description, now edit tokens as
named pills, Yoast style. *Insert variable* (or typing `[`) offers the snippet variables set in the
SEO settings, then a search over every token. A page's empty field shows its template as pills; an
edit equal to the template saves nothing, and *Use template* clears an override. A **Title
separator** setting adds `[neo-seo:separator]`. Run `drush updatedb` (`neo_seo_update_11003`): it
adds the separator (`|`, so no title changes) and the shipped variables, and moves the global title
template onto the separator token only if it is still the one shipped.

## The SEO panel is one column, under a score ring

**The SEO checks sat in two uneven columns.** The keyphrase, alt text and heading outline cards
beside the checks never matched their height, and the side cards squeezed long messages into a
thin strip. Each score now has a header — a ring with the score, what it was measured against, and
for SEO the keyphrase and cornerstone fields — above one column of checks grouped *Problems*,
*Improvements* and a folded *N passed*, with the heading outline last; each group's heading carries
its severity icon (a red disc, an amber triangle, a green tick) and count. The heading and alt text
checks join the SEO list, tagged *Not scored*. A check that can be fixed is a row-wide button to
its fix, "Fix ›" at its end; *Show in preview* is an eye beside it, amber while its marks show. The edit form, the Alchemist dropdown (now 520px for both views), the top bar pills and
the Save summary share the design. Without a focus keyphrase the SEO score is shown everywhere as
the partial score, the pill's tooltip saying the keyphrase checks are not in it, where before the
pill showed none.

## The alt text check lists each image, and fixes it on its media item

**An image flagged for alt text seemed to have it, and Fix went only to the first one.** The
message said "have no alt text, or use the file name" without saying which or where, and its Fix
opened the component showing the first image — for an image a card pulls from another page, a
component with no alt text field. The check is now one row, "2 images need better alt text",
over a list of the images, one per file however often the page shows it: a thumbnail, the file
name, why (no alt text, or just the file name) and how often it is used, each with its own Show
in preview and Fix. Fix opens the image's media item — where alt text is kept — in a modal over
the editor; saving there closes it and the panel checks again. Without a media item, or without
access to edit it, Fix goes to the component as before. neo_seo_analysis adds the lookup route
`/neo-seo/images` (permission *edit neo_seo overrides*); the media module stays optional.

## The schemamap is announced with a `Schemamap:` directive

**The Yoast visualizer did not find a site's schemamap from its home page.** robots.txt carried
it as `Sitemap:`, but the Schema Feeds specification, which the visualizer and NLWeb agents
follow, announces it with its own `Schemamap:` directive. The robotstxt module now prints
`Schemamap: https://example.com/schemamap.xml`; a site with a static robots.txt should change
its line by hand. Each schemamap entry also carries Schema Feeds' `<sf:contentType>` element
alongside the attribute, as Yoast SEO prints it.

## Schemamap feeds carry the site's own address

**A feed warmed from Drush listed every @id as `http://` on an `https://` site**, so the
schema visualizer could not join the feeds' nodes to the pages' own graphs. Each entity's
cached nodes were keyed without the site's address, and whichever request built them first
fixed it for everyone. They are now kept per address (the `url.site` cache context). Warm at
the address visitors use — `drush --uri=https://example.com neo-seo:schemamap --warm` — and
the command warns when it is warming for `http://`.

## Schemamap feeds build within a request's memory

**Feeds of pages with large layouts failed (502) on a host with a memory limit.** Building
a page's graph checked access on every component of its layout, which resolves each
component's values — for an image, building its derivative — and a feed kept every page's
entities and statics until it ended. Only the components that declare schema, and those
that hold one, are looked at now, and each page's entities and statics are released before
the next: the peak for a feed fell from about 480MB to under 140MB.

## The whole site's schema at /schemamap.xml

**A schema visualizer, or an AI agent, saw one page's graph at a time.** `/schemamap.xml`
now lists every page's schema as feeds, in Yoast SEO's schema aggregation format, so the
Yoast visualizer (with *Follow Schemamap*) and NLWeb agents read the whole site. Each feed
holds the graphs of up to 100 published entities of a bundle, one JSON-LD node per line,
built as an anonymous visitor and cached per entity; pages kept out of search results, and
bundles whose robots default is `noindex`, are left out. It is on by default — run
`drush updatedb` (`neo_seo_update_11002`) — and *Publish a schemamap* in the settings turns
it off. With the robotstxt module, robots.txt gets a `Sitemap:` line for it; a static
robots.txt takes the line by hand. `drush neo-seo:schemamap --warm` builds and caches every
feed.

## An Article without authors names the site as its author

**An Article whose author field referenced no one had no author.** The site
identity — the organization, or the person a personal site is — is now its
author, as it is already its publisher. Google recommends an author on every
Article and accepts an organization. A bundle with an author field still lists
the people it references; the site is only the fallback.

## A share image is never enlarged

A share image the share image style would make bigger than it is — a small image
cropped up to 1200×630 — is now shared as it is, at its own size, instead of
enlarged and blurred. Larger images go through the style as before.

## A component declares its schema under a top-level `seo` key

**Breaking: `thirdPartySettings.neo_seo.pieces` is no longer read.** A component
now declares the schema.org nodes it adds to the page under a top-level `seo` key
in its `.component.yml`, shaped like the JSON-LD it produces:

```yaml
seo:
  - '@type': ProfessionalService
    '@each': locations as office
    name: '{{ office.title }}'
    address: '{{ office.address|postal_address }}'
    parentOrganization: '{{ graph.organization }}'
```

Values read props, rows and the reserved `graph`, `page` and `site` roots through
`{{ path|filter }}`; `@each` repeats a node or builds a list at any depth, `@if`
keeps one only when a value holds, `@required` drops one missing a property, and
`@id` is optional and kept unique on the page. The filters are `postal_address`,
`url`, `image`, `text`, `date`, `slug`, `first` and `join`. Empty values are left
out. A declaration in the old form adds nothing until it is rewritten; the README
has the syntax and the neo-component skill the full reference.

With neo_alchemist, `drush neo:alchemist:validate` lints a declaration, the
component audit reports a node a page would leave out and shows the JSON-LD on
the component's preview page, and `drush neo-seo:component <id>` prints what a
component's examples produce.

## The edit form's SEO section is three: Search & social, SEO and Readability

**One long "SEO" section held the previews, the overrides and the analysis.** The
edit form sidebar now has three sections, all closed to start, matching the
Alchemist editor: **Search & social** (the previews, title, description, share
image and hide-from-search), **SEO** (the focus keyphrase, cornerstone and the SEO
checks) and **Readability** (with neo_seo_analysis). The controls keep their names,
so nothing that is saved changes. A page built in its own Alchemist layout gets no
Readability section: its checks are made in the layout editor, so its SEO section
shows the two scores and **Open in the layout editor**, which opens the editor on
its SEO checks and asks first when the form has unsaved changes. The hide-from-search
and cornerstone toggles are drawn at the small size.

## The share image and the logo are chosen in the media library

**Share image was an autocomplete over media labels.** With media_library enabled,
Share image and the settings' logo are a thumbnail with **Choose image**, which
opens the media library limited to image media, and **Remove**. It works in the
Alchemist editor's panel too. While Share image is empty, the panel shows the image
the page shares on its own. Without media_library both stay an autocomplete.

## The site identity can take its social profiles and its logo from the site

**The social profiles and the logo were typed into neo_seo's settings by hand.**
The settings can now take the social profiles from a neo_site_settings type (its
filled link fields, in display order; any profiles typed there are merged in) and
the logo from neo_favicon (its largest icon, uncropped). Both modules stay optional.
Each is turned on where it is available and neo_seo has nothing of its own: on
install, by `neo_seo_update_11001`, and when either module is installed later —
run `drush updatedb`. A typed list of profiles is replaced only when site settings
already holds every URL in it. The metatag migration maps a `[neo:logo]` logo to the
favicon the same way.

## Suggested descriptions come from visible prose

**The suggestion could read an address or a hidden error message.** A `<br>` now
breaks a line rather than running two together, a block of short lines (an address,
contact details) is not taken for prose, and text the page hides — `hidden`,
`aria-hidden`, `noscript`, `sr-only`, a `hidden` class no breakpoint shows again — is
skipped. A page with no prose gets no suggestion.

## The analysis is easier to read, and to tell from the panel

- **The Alchemist panel is "Search & social"**, so it is not confused with the SEO
  score in the top bar. The SEO dropdown links to it, and the panel shows both scores
  at its top, each opening its checks.
- **The SEO dropdown fits without scrolling:** 760px wide, its keyphrase,
  cornerstone, alt text and heading outline beside the checks, one column again where
  there is less room. Readability is 520px.
- **Passed checks fold into one "N passed" row**, and the heading outline under
  "N headings", open when the outline has a problem. Each choice is remembered in the
  browser.
- **Help is in the site's "?" badges**: each SEO field carries its help as its
  description, and each score its meaning beside its heading.
- **A missing keyphrase shows as the SEO pill's grey dot**, without "– set keyphrase";
  its tooltip still says to set one.
- **The score dot's ring stays centred in Firefox.**

`neo_seo_analysis` now requires neo_tooltip.
