<?php

/**
 * @file
 * Hooks provided by neo_seo.
 */

use Drupal\neo_seo\Graph\PageContext;

/**
 * @addtogroup hooks
 * @{
 */

/**
 * Alters the page's schema graph after every piece has contributed.
 *
 * To add a node, prefer a graph piece plugin (Plugin/GraphPiece) — it is
 * discovered, ordered and cached with the rest. This hook is for changing what
 * other pieces emitted. Use \Drupal\neo_seo\Graph\SchemaIds for any id, and
 * record what you read with $context->addCacheableDependency().
 *
 * @param array $nodes
 *   The graph's nodes, a list.
 * @param \Drupal\neo_seo\Graph\PageContext $context
 *   The page being described.
 */
function hook_neo_seo_graph_alter(array &$nodes, PageContext $context): void {
  foreach ($nodes as &$node) {
    if ($node['@type'] === 'WebSite') {
      $node['alternateName'] = 'WPS';
    }
  }
}

/**
 * Alters the graph piece definitions.
 *
 * @param array $definitions
 *   The definitions, keyed by plugin id.
 */
function hook_neo_seo_graph_piece_info_alter(array &$definitions): void {
  unset($definitions['website']);
}

/**
 * @} End of "addtogroup hooks".
 */
