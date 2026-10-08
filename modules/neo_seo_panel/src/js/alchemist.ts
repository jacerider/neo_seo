/**
 * Neo | SEO Panel — the Alchemist editor's SEO side panel.
 *
 * Registers the SeoPanel plugin's client half. The panel itself is
 * neo_seo_panel/panel; this only hands it the panel body and the draft api
 * when the editor first opens the panel, and the top bar badge (when the
 * plugin rendered one) at editor load.
 */
(function (Drupal): void {
  Drupal.neoAlchemist?.panels?.register('neo_seo', {
    mount(element, api) {
      Drupal.neoSeoPanel?.mountAlchemist(element, api);
    },
    badge(element, api, dropdown) {
      Drupal.neoSeoPanel?.badgeAlchemist(element, api, dropdown);
    },
  });
})(Drupal);
