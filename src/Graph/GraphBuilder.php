<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Graph;

use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Assembles the page's @graph from every needed graph piece.
 */
class GraphBuilder {

  /**
   * Constructs the graph builder.
   *
   * @param \Drupal\neo_seo\Graph\GraphPiecePluginManager $pieceManager
   *   The graph piece plugin manager.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler, for hook_neo_seo_graph_alter().
   */
  public function __construct(
    protected GraphPiecePluginManager $pieceManager,
    protected ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * Builds the JSON-LD document for a page.
   *
   * @param \Drupal\neo_seo\Graph\PageContext $context
   *   The page. Pieces record what they read on it, so read its cacheability
   *   after this returns.
   *
   * @return array{"@context": string, "@graph": array<int, array>}
   *   The document. `@graph` is empty when no piece is needed.
   */
  public function build(PageContext $context): array {
    $nodes = [];
    foreach (array_keys($this->pieceManager->getSortedDefinitions()) as $pluginId) {
      /** @var \Drupal\neo_seo\Graph\GraphPieceInterface $piece */
      $piece = $this->pieceManager->createInstance($pluginId);
      if (!$piece->isNeeded($context)) {
        continue;
      }
      foreach ($piece->generate($context) as $node) {
        $node = static::prune($node);
        if ($node) {
          $nodes[] = $node;
        }
      }
    }
    $this->moduleHandler->alter('neo_seo_graph', $nodes, $context);
    return [
      '@context' => 'https://schema.org',
      '@graph' => array_values($nodes),
    ];
  }

  /**
   * Drops empty values all the way down.
   *
   * NULL, '' and [] carry nothing in JSON-LD, and an empty property reads to a
   * validator as a broken one. `0` and FALSE are values and stay. A list keeps
   * its keys contiguous once members are dropped, so it encodes as a JSON
   * array rather than an object.
   *
   * @param array $value
   *   A node or nested value.
   *
   * @return array
   *   The value without its empty members.
   */
  public static function prune(array $value): array {
    $isList = array_is_list($value);
    foreach ($value as $key => $item) {
      if (is_array($item)) {
        $item = static::prune($item);
        $value[$key] = $item;
      }
      if ($item === NULL || $item === '' || $item === []) {
        unset($value[$key]);
      }
    }
    return $isList ? array_values($value) : $value;
  }

}
