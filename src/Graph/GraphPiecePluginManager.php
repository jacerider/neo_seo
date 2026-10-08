<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Graph;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\neo_seo\Attribute\GraphPiece;

/**
 * Discovers graph pieces under `Plugin/GraphPiece` in every module.
 */
class GraphPiecePluginManager extends DefaultPluginManager {

  /**
   * Constructs the graph piece plugin manager.
   *
   * @param \Traversable $namespaces
   *   The namespaces to search for plugins.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   *   The cache backend.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct('Plugin/GraphPiece', $namespaces, $module_handler, GraphPieceInterface::class, GraphPiece::class);
    $this->alterInfo('neo_seo_graph_piece_info');
    $this->setCacheBackend($cache_backend, 'neo_seo_graph_piece_plugins');
  }

  /**
   * The piece definitions in graph order: weight, then id.
   *
   * @return array<string, array>
   *   The definitions, keyed by plugin id.
   */
  public function getSortedDefinitions(): array {
    $definitions = $this->getDefinitions();
    uasort($definitions, static function (array $a, array $b): int {
      return [$a['weight'] ?? 0, $a['id']] <=> [$b['weight'] ?? 0, $b['id']];
    });
    return $definitions;
  }

}
