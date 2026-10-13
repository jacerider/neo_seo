<?php

declare(strict_types=1);

namespace Drupal\neo_seo_analysis;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\neo_alchemist\Entity\ComponentFieldConfig;

/**
 * Whether a page's content is built in its own Alchemist layout.
 *
 * Such a page is written in the Alchemist editor, not on its edit form: the
 * analysis's checks cannot be acted on from the form, so the form shows the
 * scores and a way to the editor, where they can. A page whose layout is
 * locked is fed by the form's own fields, and keeps the checks. neo_alchemist
 * is optional; without it no page has such a layout.
 */
class AlchemistLayout {

  /**
   * The view mode a page's content is rendered in, as neo_alchemist reads it.
   */
  protected const VIEW_MODE = 'full';

  /**
   * Constructs the check.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   */
  public function __construct(
    protected ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * The field holding the page's own layout, if it has one.
   *
   * A component tree that allows a layout of the page's own (custom), shown
   * by the view mode the page is rendered in.
   *
   * @return string|null
   *   The field name, or NULL when the page has no custom layout.
   */
  public function customField(ContentEntityInterface $entity): ?string {
    if (!$this->moduleHandler->moduleExists('neo_alchemist')) {
      return NULL;
    }
    $display = EntityViewDisplay::collectRenderDisplay($entity, static::VIEW_MODE);
    foreach ($entity->getFieldDefinitions() as $name => $definition) {
      if (
        $definition->getType() === 'neo_component_tree'
        && $definition->getSetting('allow_custom')
        && ($display->getComponent($name)['type'] ?? NULL) === 'neo_component_tree'
      ) {
        return (string) $name;
      }
    }
    return NULL;
  }

  /**
   * The page's layout editor, opening on its SEO checks.
   *
   * @return \Drupal\Core\Url|null
   *   The editor, or NULL when the page is not saved yet or the account may
   *   not open it.
   */
  public function editorUrl(ContentEntityInterface $entity, string $field, AccountInterface $account): ?Url {
    if ($entity->isNew() || !$entity->hasLinkTemplate('alchemist.manage')) {
      return NULL;
    }
    $url = $entity->toUrl('alchemist.manage', ['query' => ['neo_seo' => 'seo']])
      ->setRouteParameter('neo_field', ComponentFieldConfig::getKeyFromFieldname($field));
    return $url->access($account) ? $url : NULL;
  }

}
