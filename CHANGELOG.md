# Changelog

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
