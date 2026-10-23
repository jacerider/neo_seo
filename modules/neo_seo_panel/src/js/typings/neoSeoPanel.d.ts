/**
 * The SEO panel's public shapes, shared by its hosts and its extensions.
 *
 * Global (not exported) because every entrypoint is built as its own classic
 * script: the panel, its Alchemist registration and an extension such as
 * neo_seo_analysis meet at runtime through Drupal.neoSeoPanel, never through
 * an import.
 */

/** The SEO overrides, as the panel reads and writes them. */
interface NeoSeoOverrides {
  title: string;
  description: string;
  /** The share image's media id, or null. */
  image: number | null;
  keyphrase: string;
  page_type: string;
  article_type: string;
  noindex: boolean;
  cornerstone: boolean;
  seo_score: number | null;
  readability_score: number | null;
  /** The editor's part of the page's address (ADR 0005). */
  slug: string;
  /**
   * The URL controls: not overrides, but drafted and sent with them, and
   * applied to the page's path (neo_seo's PageUrl).
   */
  url_custom: boolean;
  url_alias: string;
}

type NeoSeoOverrideKey = keyof NeoSeoOverrides;

/** What neo_seo's draft render answers: the page as it would be. */
interface NeoSeoDraftRender {
  html: string;
  url: string;
  language: string;
  head: {
    title: string;
    description: string;
    social_title: string;
    robots: string;
    canonical: string;
    image: { url: string; width: number | null; height: number | null; alt: string | null } | null;
    page_type: string;
    article_type: string;
    site_name: string;
  };
  overrides: { keyphrase: string; cornerstone: boolean };
  /** What each token in the title and description gives on the draft. */
  tokens?: Record<string, string>;
  /** The address the page will have once saved, where neo_seo manages it. */
  path?: NeoSeoPagePath | null;
}

/** A page's address once saved (neo_seo's PageUrl::describe()). */
interface NeoSeoPagePath {
  custom: boolean;
  /** Null until a new page is saved. */
  alias: string | null;
  url: string | null;
  /** The pattern's part of the alias, before the slug. */
  prefix: string;
  /**
   * The slug as it will be saved: cleaned, without the suffix pathauto adds
   * to an address that is another page's (all of the alias when custom).
   */
  slug: string;
  /** What the editor sent: the slug, or the custom URL as typed. */
  input: string;
  /** The slug the title gives, which an empty slug follows. */
  placeholder: string;
  /** Whether the site sets the whole address (no slug in its pattern). */
  automatic: boolean;
  /** Whether the slug's address is another page's. */
  taken: boolean;
  saved: string | null;
  changed: boolean;
  /** Whether saving moves a published page. */
  moving: boolean;
  /** Whether the old address will redirect to the new one. */
  redirect: boolean;
  /** How many pages under this one move with it. */
  children: number;
  notice: string | null;
  error: string | null;
  /**
   * What the editor should know about it, as callouts: markup neo_seo
   * renders (PageUrlField::notes()), its texts escaped there.
   */
  notes_html: string;
}

/** Something to point at in the page preview (neo_seo_analysis highlights). */
type NeoSeoHighlight =
  | { kind: 'sentence'; text: string }
  | { kind: 'paragraph'; text: string }
  | { kind: 'heading'; level: number; text: string }
  | { kind: 'image'; src: string; alt: string | null }
  | { kind: 'link'; href: string; text: string };

/**
 * Where a fix went: an SEO field, a component's field, or only the component.
 */
type NeoSeoFixResult = 'field' | 'component-field' | 'component' | null;

/** Why a host reports a change. */
interface NeoSeoHostChange {
  /**
   * `override` — an SEO override control changed (`key` names it).
   * `content` — something else the page renders from changed: a field in the
   * edit form, the layout in Alchemist.
   */
  source: 'override' | 'content';
  key?: NeoSeoOverrideKey;
}

/**
 * The host contract: the only thing the panel knows about where it lives.
 *
 * The host owns the override controls and where their values go (form inputs
 * saved with the form, or the Alchemist shared draft); the panel reads them,
 * asks for renders and writes back what it computes.
 */
interface NeoSeoPanelHost {
  readonly kind: 'form' | 'alchemist';
  /** What the override controls hold now. */
  read(): NeoSeoOverrides;
  /**
   * Writes override values. A `quiet` write records a computed value (the
   * analysis scores): it rides along with the editor's own changes but never
   * on its own marks the page changed, so opening the panel creates no draft
   * and no "unsaved changes". A normal write marks the page changed.
   */
  write(values: Partial<NeoSeoOverrides>, options?: { quiet?: boolean }): void;
  /** The wrapper of an override's control, for the panel to annotate or regroup. */
  control(key: NeoSeoOverrideKey): HTMLElement | null;
  /** The page as the current values would render it. */
  render(): Promise<NeoSeoDraftRender>;
  /** Subscribes to changes the panel should react to. */
  onChange(callback: (change: NeoSeoHostChange) => void): void;
  /** Marks text in the host's page preview; false when the host has none. */
  highlight?(targets: NeoSeoHighlight[] | null): boolean;
  /**
   * Goes to where an assessment is fixed (Alchemist): an SEO field, or the
   * component (and its field) a highlight is found in. Null when nothing was
   * found.
   */
  fix?(id: string, highlights: NeoSeoHighlight[]): NeoSeoFixResult;
  /** Whether fix() has anywhere to go for this assessment. */
  canFix?(id: string, highlights: NeoSeoHighlight[]): boolean;
  /** Opens the panel the SEO fields live in (Alchemist); FALSE when there is none. */
  showPanel?(): boolean;
}

/** The panel's browser config: the mount element's data-neo-seo-panel JSON. */
interface NeoSeoPanelConfig {
  host: 'form' | 'alchemist';
  entityType: string;
  entityId: string | null;
  renderUrl: string | null;
  favicon: string | null;
  [extension: string]: unknown;
}

/** What an extension's mount() receives. */
interface NeoSeoPanelContext {
  host: NeoSeoPanelHost;
  config: NeoSeoPanelConfig;
  /**
   * A section's body: the form's own place for it when it keeps one
   * (data-neo-seo-section="<id>"), else a titled section added to the panel.
   */
  section(id: string, title: string): HTMLElement;
  /** Puts an element at the top of the panel, above the previews. */
  top(element: HTMLElement): void;
  /** The latest draft render, if one has arrived. */
  latest(): NeoSeoDraftRender | null;
  /** Called with every draft render as it arrives. */
  onRender(callback: (render: NeoSeoDraftRender) => void): void;
  /** Renders the draft again, e.g. after something it shows was saved elsewhere. */
  refresh(): void;
}

/** A module's addition to the panel. */
interface NeoSeoPanelExtension {
  id: string;
  mount(context: NeoSeoPanelContext): void;
}

/** Where an Alchemist editor's SEO state stands. */
type NeoSeoEditorState = 'updating' | 'ready' | 'error';

/**
 * One Alchemist editor's SEO state: its host and latest draft render, kept
 * current from editor load. Shared by the panel and its top bar badge.
 */
interface NeoSeoEditor {
  readonly host: NeoSeoPanelHost;
  readonly config: NeoSeoPanelConfig;
  latest(): NeoSeoDraftRender | null;
  onRender(callback: (render: NeoSeoDraftRender) => void): void;
  onState(callback: (state: NeoSeoEditorState) => void): void;
  /** Another element holding override controls (moved there from the panel). */
  addRoot(root: HTMLElement): void;
  refresh(): void;
}

/** A module's addition to the Alchemist top bar badge (neo_seo_analysis). */
interface NeoSeoBadgeExtension {
  id: string;
  mount(badge: HTMLElement, dropdown: NeoAlchemistDropdown, editor: NeoSeoEditor): void;
}

interface NeoSeoPanelApi {
  /** Adds an extension to every panel, including ones already mounted. */
  extend(extension: NeoSeoPanelExtension): void;
  /** Adds to the Alchemist top bar badge, including one already started. */
  extendBadge(extension: NeoSeoBadgeExtension): void;
  /** Mounts the panel in the Alchemist editor (neo_seo_panel/alchemist). */
  mountAlchemist(element: HTMLElement, api: NeoAlchemistPanelApi): void;
  /** Starts the Alchemist top bar badge at editor load (neo_seo_panel/alchemist). */
  badgeAlchemist(element: HTMLElement, api: NeoAlchemistPanelApi, dropdown: NeoAlchemistDropdown): void;
}

declare namespace drupal {
  export interface IDrupalStatic {
    neoSeoPanel?: NeoSeoPanelApi;
  }
}
