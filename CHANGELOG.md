# Changelog

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
