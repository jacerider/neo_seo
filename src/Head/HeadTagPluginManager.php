<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Head;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\neo_seo\Attribute\HeadTag;

/**
 * Discovers head tags under `Plugin/HeadTag` in every module.
 */
class HeadTagPluginManager extends DefaultPluginManager {

  /**
   * Constructs the head tag plugin manager.
   *
   * @param \Traversable $namespaces
   *   The namespaces to search for plugins.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   *   The cache backend.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct('Plugin/HeadTag', $namespaces, $module_handler, HeadTagInterface::class, HeadTag::class);
    $this->alterInfo('neo_seo_head_tag_info');
    $this->setCacheBackend($cache_backend, 'neo_seo_head_tag_plugins');
  }

  /**
   * The tag definitions in print order: weight, then id.
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

  /**
   * Every template field the tags declare, in print order.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup|string>
   *   Field name => label.
   */
  public function getFields(): array {
    $fields = [];
    foreach ($this->getSortedDefinitions() as $definition) {
      $fields += $definition['fields'] ?? [];
    }
    return $fields;
  }

}
