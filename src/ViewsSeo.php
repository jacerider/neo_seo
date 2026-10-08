<?php

declare(strict_types=1);

namespace Drupal\neo_seo;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\neo_seo\Graph\PageContext;
use Drupal\neo_seo\Plugin\views\display_extender\SeoDisplayExtender;
use Drupal\views\Views;

/**
 * Reads a Views page's SEO settings from the view's config.
 *
 * The view need not run: the settings live in the display's options.
 */
class ViewsSeo {

  /**
   * Constructs the reader.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * The SEO settings of the page's view display, or NULL off a Views page.
   *
   * @return array{title: string, description: string, image: string, page_type: string}|null
   *   The settings, empty strings where unset.
   */
  public function settings(PageContext $context): ?array {
    if (!$context->viewId || !$this->moduleHandler->moduleExists('views')) {
      return NULL;
    }
    $view = $this->entityTypeManager->getStorage('view')->load($context->viewId);
    if (!$view) {
      return NULL;
    }
    $context->addCacheableDependency($view);
    $display = $view->getDisplay((string) $context->viewDisplayId);
    $seo = $display['display_options']['display_extenders']['neo_seo']['seo'] ?? [];
    return array_map('strval', array_intersect_key((array) $seo, SeoDisplayExtender::DEFAULTS)) + SeoDisplayExtender::DEFAULTS;
  }

  /**
   * The token data for a Views page: the view, its display and arguments set.
   */
  public function tokenData(PageContext $context): array {
    if (!$context->viewId || !$this->moduleHandler->moduleExists('views')) {
      return [];
    }
    $view = Views::getView($context->viewId);
    if (!$view || !$view->setDisplay((string) $context->viewDisplayId)) {
      return [];
    }
    $view->setArguments($context->viewArguments);
    return ['view' => $view];
  }

}
