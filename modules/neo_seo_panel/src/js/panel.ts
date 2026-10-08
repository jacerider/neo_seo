/**
 * Neo | SEO Panel.
 *
 * Mounts the SEO panel on every [data-neo-seo-panel] element the edit form
 * renders, and exposes Drupal.neoSeoPanel: extend() for modules that add to
 * the panel (neo_seo_analysis), extendBadge() for the Alchemist top bar
 * badge, and mountAlchemist()/badgeAlchemist() for the Alchemist editor's
 * registration (alchemist.ts). In Alchemist both the panel and the badge read
 * one AlchemistEditor, started by whichever comes first — the badge, at
 * editor load. The panel and its hosts are all in this one entrypoint; the
 * others reach it through Drupal.neoSeoPanel.
 */

import { AlchemistEditor } from './editor';
import { FormHost } from './hosts/form';
import { SeoPanel } from './widget';

(function (Drupal): void {

  const extensions: NeoSeoPanelExtension[] = [];
  const panels: SeoPanel[] = [];
  const badgeExtensions: NeoSeoBadgeExtension[] = [];
  let badge: { element: HTMLElement; dropdown: NeoAlchemistDropdown } | null = null;
  let editor: AlchemistEditor | null = null;

  /**
   * The editor's SEO state, started once from the panel's mount element —
   * rendered (hidden) with the editor, so it is there before the panel opens.
   */
  function alchemistEditor(api: NeoAlchemistPanelApi): AlchemistEditor | null {
    if (!editor) {
      const element = document.querySelector<HTMLElement>('.neo-seo-panel-form [data-neo-seo-panel]');
      const form = element?.closest<HTMLElement>('form');
      if (!element || !form) {
        return null;
      }
      editor = new AlchemistEditor(form, api, config(element));
    }
    return editor;
  }

  function badgeExtend(extension: NeoSeoBadgeExtension): void {
    if (!badge || !editor) {
      return;
    }
    try {
      extension.mount(badge.element, badge.dropdown, editor);
    }
    catch (e) {
      console.error(e);
    }
  }

  function config(element: HTMLElement): NeoSeoPanelConfig {
    return JSON.parse(element.dataset.neoSeoPanel || '{}') as NeoSeoPanelConfig;
  }

  function start(element: HTMLElement, host: NeoSeoPanelHost, settings: NeoSeoPanelConfig): void {
    const panel = new SeoPanel(element, host, settings);
    panels.push(panel);
    extensions.forEach((extension) => panel.extend(extension));
  }

  Drupal.neoSeoPanel = {
    extend(extension) {
      extensions.push(extension);
      panels.forEach((panel) => panel.extend(extension));
    },
    extendBadge(extension) {
      badgeExtensions.push(extension);
      badgeExtend(extension);
    },
    mountAlchemist(body, api) {
      const element = body.querySelector<HTMLElement>('[data-neo-seo-panel]');
      const shared = alchemistEditor(api);
      if (!element || !shared) {
        return;
      }
      const panel = new SeoPanel(element, shared.host, shared.config, shared);
      panels.push(panel);
      extensions.forEach((extension) => panel.extend(extension));
    },
    badgeAlchemist(element, api, dropdown) {
      if (badge || !alchemistEditor(api)) {
        return;
      }
      badge = { element, dropdown };
      badgeExtensions.forEach(badgeExtend);
    },
  };

  Drupal.behaviors.neoSeoPanel = {
    attach(context: HTMLElement) {
      once('neo-seo-panel', '[data-neo-seo-panel]', context).forEach((element) => {
        const settings = config(element);
        // The Alchemist editor mounts its panel when it is first opened.
        if (settings.host !== 'form') {
          return;
        }
        const scope = element.closest<HTMLElement>('.neo-seo-panel-host') ?? element.parentElement;
        if (!scope) {
          return;
        }
        try {
          start(element, new FormHost(element, scope), settings);
        }
        catch (e) {
          console.error(e);
        }
      });
    },
  };

})(Drupal);
