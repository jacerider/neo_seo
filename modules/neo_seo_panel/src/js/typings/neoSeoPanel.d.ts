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
  /** Adds a titled section to the panel and returns its body. */
  section(id: string, title: string): HTMLElement;
  /** Puts an element at the top of the panel, above the previews. */
  top(element: HTMLElement): void;
  /** The latest draft render, if one has arrived. */
  latest(): NeoSeoDraftRender | null;
  /** Called with every draft render as it arrives. */
  onRender(callback: (render: NeoSeoDraftRender) => void): void;
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
