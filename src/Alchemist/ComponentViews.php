<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Alchemist;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\neo_alchemist\ComponentInstanceInterface;
use Drupal\neo_alchemist\Plugin\ComponentValue\ViewsValue;
use Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\neo_seo\Graph\PageContext;
use Drupal\views\ViewExecutable;

/**
 * The views the components on a page's entity list, executed.
 *
 * A component lists a view's rows through neo_alchemist's `views` value
 * provider, which executes the view as the component's prop shapes are
 * built. That happens here whether or not the page's render came from the
 * cache, so what a page lists can be described on every request.
 */
class ComponentViews {

  /**
   * Constructs the finder.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   */
  public function __construct(
    protected ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * The executed views of the published components on the page's entity.
   *
   * @return \Drupal\views\ViewExecutable[]
   *   In reading order.
   */
  public function views(PageContext $context): array {
    $entity = $context->entity;
    if (!$entity instanceof FieldableEntityInterface || !$this->moduleHandler->moduleExists('neo_alchemist') || !$this->moduleHandler->moduleExists('views')) {
      return [];
    }
    // Building a views-bound shape while a view is executing recurses.
    if (ViewsValue::isExecuting()) {
      return [];
    }
    $views = [];
    foreach ($entity->getFieldDefinitions() as $name => $definition) {
      if ($definition->getType() !== 'neo_component_tree') {
        continue;
      }
      $item = $entity->get($name)->first();
      if ($item instanceof ComponentTreeItem) {
        $relevant = [];
        $this->listing($item, ComponentTreeStructure::ROOT_UUID, $relevant);
        $this->walk($item, ComponentTreeStructure::ROOT_UUID, $context, $views, $relevant);
      }
    }
    return $views;
  }

  /**
   * Walks the published children of one parent, depth first.
   */
  protected function walk(ComponentTreeItem $item, string $parentUuid, PageContext $context, array &$views, array $relevant): void {
    foreach ($item->getComponents($parentUuid) as $uuid => $instance) {
      // Only what lists a view, or holds what does, is looked at: building a
      // component's shapes resolves its values.
      if (empty($relevant[(string) $uuid]) || !$instance instanceof ComponentInstanceInterface || !$instance->isPublished()) {
        continue;
      }
      $access = $instance->access('view', NULL, TRUE);
      $context->addCacheableDependency($access);
      if (!$access->isAllowed()) {
        continue;
      }
      if (static::bindsView($instance->getSettings())) {
        foreach ($instance->getPropShapeContexts('views') as $shape) {
          if (($shape['value'] ?? NULL) instanceof ViewExecutable) {
            $views[] = $shape['value'];
          }
        }
        $context->addCacheableDependency($instance->getCacheableMetadata());
      }
      $this->walk($item, (string) $uuid, $context, $views, $relevant);
    }
  }

  /**
   * Marks the components that list a view, or hold one that does.
   *
   * From their settings alone: nothing is resolved.
   *
   * @return bool
   *   Whether anything beneath the parent lists a view.
   */
  protected function listing(ComponentTreeItem $item, string $parentUuid, array &$relevant): bool {
    $any = FALSE;
    foreach ($item->getComponents($parentUuid) as $uuid => $instance) {
      $lists = $instance instanceof ComponentInstanceInterface && static::bindsView($instance->getSettings());
      $beneath = $this->listing($item, (string) $uuid, $relevant);
      $relevant[(string) $uuid] = $lists || $beneath;
      $any = $any || $relevant[(string) $uuid];
    }
    return $any;
  }

  /**
   * Whether a component's settings bind a prop to the `views` provider.
   */
  protected static function bindsView(array $settings): bool {
    if (($settings['id'] ?? NULL) === 'views' && !empty($settings['settings']['view_id'])) {
      return TRUE;
    }
    foreach ($settings as $value) {
      if (is_array($value) && static::bindsView($value)) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
