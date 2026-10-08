/**
 * Neo | SEO Panel.
 *
 * Mounts the SEO panel on every [data-neo-seo-panel] element the edit form
 * renders, and exposes Drupal.neoSeoPanel: extend() for modules that add to
 * the panel (neo_seo_analysis), mountAlchemist() for the Alchemist editor's
 * registration (alchemist.ts). The panel and its hosts are all in this one
 * entrypoint; the others reach it through Drupal.neoSeoPanel.
 */

import { AlchemistHost } from './hosts/alchemist';
import { FormHost } from './hosts/form';
import { SeoPanel } from './widget';

(function (Drupal): void {

  const extensions: NeoSeoPanelExtension[] = [];
  const panels: SeoPanel[] = [];

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
    mountAlchemist(body, api) {
      const element = body.querySelector<HTMLElement>('[data-neo-seo-panel]');
      const form = element?.closest<HTMLElement>('form');
      if (!element || !form) {
        return;
      }
      const settings = config(element);
      start(element, new AlchemistHost(form, api, settings), settings);
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
