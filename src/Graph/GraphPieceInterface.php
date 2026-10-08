<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Graph;

use Drupal\Component\Plugin\PluginInspectionInterface;

/**
 * A contributor to a page's schema.org @graph.
 *
 * The engine asks every piece whether it is needed for the page, and collects
 * the nodes of those that are. A piece never builds an @id by hand: it asks
 * SchemaIds, so two pieces naming the same thing always agree on its id, and a
 * reference ({"@id": …}) from one piece always lands on a node another emits.
 */
interface GraphPieceInterface extends PluginInspectionInterface {

  /**
   * Whether this piece contributes to the page.
   *
   * @param \Drupal\neo_seo\Graph\PageContext $context
   *   The page being described.
   *
   * @return bool
   *   TRUE when generate() should run.
   */
  public function isNeeded(PageContext $context): bool;

  /**
   * The nodes this piece adds to the graph.
   *
   * Empty values (NULL, '', []) are dropped by the engine, so a piece may set
   * an optional property unconditionally. Any cacheability the nodes depend on
   * goes on the context (PageContext::addCacheableDependency()).
   *
   * @param \Drupal\neo_seo\Graph\PageContext $context
   *   The page being described.
   *
   * @return array<int, array<string, mixed>>
   *   A list of schema.org nodes, each with an `@type` and an `@id`.
   */
  public function generate(PageContext $context): array;

}
