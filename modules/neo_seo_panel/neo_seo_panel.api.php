<?php

/**
 * @file
 * Hooks provided by the Neo | SEO Panel module.
 */

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * @addtogroup hooks
 * @{
 */

/**
 * Alters the SEO panel before it is mounted.
 *
 * Called once per panel, in the edit form and in the Alchemist editor. A
 * module that extends the panel in the browser (Drupal.neoSeoPanel.extend())
 * adds its library here, and anything its extension needs to the config, which
 * reaches the browser as the mount element's data-neo-seo-panel JSON.
 *
 * @param array $panel
 *   - config: the panel's browser config. Keys host, entityType, entityId,
 *     renderUrl and favicon are the panel's own; add yours under your module's
 *     name.
 *   - libraries: the libraries to attach.
 * @param \Drupal\Core\Entity\ContentEntityInterface $entity
 *   The entity being edited; new (unsaved) in an add form.
 * @param string $host
 *   'form' for the edit form, 'alchemist' for the Alchemist editor.
 */
function hook_neo_seo_panel_alter(array &$panel, ContentEntityInterface $entity, string $host): void {
  $panel['libraries'][] = 'my_module/seo_panel';
  $panel['config']['my_module'] = ['endpoint' => '/my-module/endpoint'];
}

/**
 * Fills the SEO panel's badge in the Alchemist editor's top bar.
 *
 * Empty (the default) means no badge. What is added is rendered left of the
 * editor's scale controls, where its client half — a
 * Drupal.neoSeoPanel.extendBadge() extension — keeps it current from editor
 * load. Every <button> in it toggles the badge's dropdown.
 *
 * @param array $badge
 *   The badge render array.
 * @param \Drupal\Core\Entity\ContentEntityInterface $entity
 *   The entity being edited.
 */
function hook_neo_seo_panel_badge_alter(array &$badge, ContentEntityInterface $entity): void {
  $badge['my_module'] = ['#markup' => '<button type="button">SEO</button>'];
}

/**
 * @} End of "addtogroup hooks".
 */
