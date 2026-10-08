/**
 * Neo | SEO Panel.
 *
 * Mounts the SEO panel on every [data-neo-seo-panel] element the edit form
 * renders, and exposes Drupal.neoSeoPanel: extend() for modules that add to
 * the panel (neo_seo_analysis). The panel and its hosts are all in this one
 * entrypoint; other entrypoints reach it through Drupal.neoSeoPanel.
 */

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
  };

  Drupal.behaviors.neoSeoPanel = {
    attach(context: HTMLElement) {
      once('neo-seo-panel', '[data-neo-seo-panel]', context).forEach((element) => {
        const settings = config(element);
        // Other hosts mount their panel themselves.
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
