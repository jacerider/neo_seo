<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Graph;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\TranslatableInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\neo_seo\Alchemist\ComponentViews;
use Drupal\neo_seo\Head\HeadValuesResolver;
use Drupal\views\ViewExecutable;
use Drupal\views\Views;

/**
 * What a collection page lists: the rows of its view, in order.
 *
 * Only on a page whose page type is CollectionPage. On a Views page the rows
 * are the route's display's; on an entity's page, those of the view a
 * component on it lists — the first that pages, else the first. A row is an
 * item when it has a page of its own, and is numbered from the pager's first
 * row: page 2 of a ten-per-page listing starts at 11.
 */
class ListedItems {

  /**
   * The items per page context.
   *
   * @var \WeakMap<\Drupal\neo_seo\Graph\PageContext, array>
   */
  private \WeakMap $items;

  /**
   * The page displays executed in this request, by "view:display".
   *
   * @var array<string, \Drupal\views\ViewExecutable>
   */
  protected array $executed = [];

  /**
   * Constructs the list.
   *
   * @param \Drupal\neo_seo\Head\HeadValuesResolver $headValues
   *   The head values resolver, for the page type.
   * @param \Drupal\neo_seo\Alchemist\ComponentViews $componentViews
   *   The views the components on an entity's page list.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   */
  public function __construct(
    protected HeadValuesResolver $headValues,
    protected ComponentViews $componentViews,
    protected ModuleHandlerInterface $moduleHandler,
  ) {
    $this->items = new \WeakMap();
  }

  /**
   * Keeps a page display's executed view, for the page it renders.
   *
   * Called from hook_views_post_execute().
   */
  public function capture(ViewExecutable $view): void {
    if ($view->display_handler?->hasPath()) {
      $this->executed[$view->id() . ':' . $view->current_display] ??= $view;
    }
  }

  /**
   * The items the page lists; empty on a page that is no collection.
   *
   * @return array<int, array{position: int, url: string}>
   *   In order.
   */
  public function items(PageContext $context): array {
    return $this->items[$context] ??= $this->build($context);
  }

  /**
   * Builds the items.
   */
  protected function build(PageContext $context): array {
    if (!$this->moduleHandler->moduleExists('views') || $this->headValues->resolve($context)->pageType !== 'CollectionPage') {
      return [];
    }
    $view = $context->viewId ? $this->pageView($context) : $this->componentView($context);
    return $view ? $this->rows($view, $context) : [];
  }

  /**
   * The executed view of a Views page.
   */
  protected function pageView(PageContext $context): ?ViewExecutable {
    $key = $context->viewId . ':' . $context->viewDisplayId;
    if (!isset($this->executed[$key])) {
      // The display's output came from the render cache, so it never ran: run
      // it here. Its results cache, where it has one, answers.
      $view = Views::getView((string) $context->viewId);
      if (!$view || !$view->setDisplay((string) $context->viewDisplayId)) {
        return NULL;
      }
      $view->preExecute($context->viewArguments);
      $view->execute();
      $this->executed[$key] ??= $view;
    }
    return $this->executed[$key];
  }

  /**
   * The view a component on an entity's page lists.
   */
  protected function componentView(PageContext $context): ?ViewExecutable {
    $views = $this->componentViews->views($context);
    foreach ($views as $view) {
      if ($view->pager?->usePager()) {
        return $view;
      }
    }
    return $views[0] ?? NULL;
  }

  /**
   * The rows of an executed view that have pages, numbered.
   */
  protected function rows(ViewExecutable $view, PageContext $context): array {
    $context->addCacheableDependency($view->display_handler->getCacheMetadata());
    $cache = $view->display_handler->getPlugin('cache');
    $context->addCacheableDependency((new CacheableMetadata())
      ->setCacheTags($cache->getCacheTags())
      ->setCacheMaxAge($cache->getCacheMaxAge()));
    $first = $view->pager ? (int) $view->pager->getCurrentPage() * (int) $view->pager->getItemsPerPage() : 0;
    $items = [];
    foreach ($view->result as $row) {
      $url = ($row->_entity ?? NULL) instanceof EntityInterface ? $this->url($row->_entity, $context) : NULL;
      if ($url !== NULL) {
        $items[] = ['position' => $first + count($items) + 1, 'url' => $url];
      }
    }
    return $items;
  }

  /**
   * A row entity's page, or NULL when it has none of its own.
   *
   * No canonical route, or one that is its edit form (media without
   * standalone URLs), is no page.
   */
  protected function url(EntityInterface $entity, PageContext $context): ?string {
    $type = $entity->getEntityType();
    if (!$entity->hasLinkTemplate('canonical') || $type->getLinkTemplate('canonical') === $type->getLinkTemplate('edit-form')) {
      return NULL;
    }
    if ($entity instanceof TranslatableInterface && $entity->hasTranslation($context->langcode)) {
      $entity = $entity->getTranslation($context->langcode);
    }
    $generated = $entity->toUrl('canonical', ['absolute' => TRUE])->toString(TRUE);
    $context->addCacheableDependency($entity)->addCacheableDependency($generated);
    return $generated->getGeneratedUrl();
  }

}
