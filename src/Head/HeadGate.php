<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Head;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Routing\AdminContext;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\neo_seo\Migration\DiffMode;
use Drupal\neo_settings\SettingsRepository;

/**
 * Decides whether neo_seo describes the current page, and owns its head.
 */
class HeadGate {

  /**
   * Constructs the gate.
   *
   * @param \Drupal\Core\Routing\AdminContext $adminContext
   *   The admin context.
   * @param \Drupal\Core\Routing\RouteMatchInterface $routeMatch
   *   The current route match.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   * @param \Drupal\neo_settings\SettingsRepository $settings
   *   The neo_seo settings repository.
   * @param \Drupal\neo_seo\Migration\DiffMode $diffMode
   *   The migration diff mode.
   */
  public function __construct(
    protected AdminContext $adminContext,
    protected RouteMatchInterface $routeMatch,
    protected ModuleHandlerInterface $moduleHandler,
    protected SettingsRepository $settings,
    protected DiffMode $diffMode,
  ) {}

  /**
   * Whether the page is one a search engine should see described.
   *
   * Not admin pages, and not the Alchemist editor's own routes.
   */
  public function describesPage(): bool {
    $route = $this->routeMatch->getRouteObject();
    return !$this->adminContext->isAdminRoute($route) && !$route?->getOption('_neo_alchemist_editor');
  }

  /**
   * Whether neo_seo prints the head tags on this page.
   *
   * Not while metatag is enabled — a page would get two titles and two
   * descriptions — unless a site builder has asked for both, to compare them
   * before switching over.
   */
  public function ownsHead(): bool {
    if (!$this->describesPage()) {
      return FALSE;
    }
    return $this->diffMode->isActive()
      || !$this->moduleHandler->moduleExists('metatag')
      || (bool) $this->settings->getActive()->getValue('head_alongside_metatag');
  }

  /**
   * Whether this is a migration diff request: neo_seo's head, metatag's gone.
   */
  public function isDiff(): bool {
    return $this->diffMode->isActive();
  }

  /**
   * The cacheability of the gate's own decision.
   */
  public function getCacheability(): CacheableMetadata {
    return (new CacheableMetadata())
      ->addCacheTags(['config:neo_seo.settings'])
      ->addCacheContexts(['route']);
  }

}
