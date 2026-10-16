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
      $node['alternateName'] = 'Acme';
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
 * Alters a page's head templates before their tokens are replaced.
 *
 * $templates holds the token strings each head field resolves from (title,
 * description, social_title, og_type, image, robots), after every default
 * layer and the editor's overrides have been applied. $data is the token data
 * they are replaced with. Use it for a page the layers cannot reach, such as a
 * custom route that describes an entity without being its canonical page: set
 * the templates, and add the entity to $data so its tokens resolve. Record
 * anything you read with $context->addCacheableDependency().
 *
 * @param array<string, string> $templates
 *   The templates, keyed by head field.
 * @param array $data
 *   The token data, keyed by token type.
 * @param \Drupal\neo_seo\Graph\PageContext $context
 *   The page being described.
 */
function hook_neo_seo_head_templates_alter(array &$templates, array &$data, PageContext $context): void {
  if ($context->routeName === 'mymodule.event_tickets') {
    $event = \Drupal::routeMatch()->getParameter('node');
    $data['node'] = $event;
    $templates['title'] = 'Tickets | [node:title] | [site:name]';
    $context->addCacheableDependency($event);
  }
}

/**
 * @} End of "addtogroup hooks".
 */
