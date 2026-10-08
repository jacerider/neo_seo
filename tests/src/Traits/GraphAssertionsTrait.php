<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_seo\Traits;

/**
 * Assertions over an emitted schema graph.
 *
 * The graph is judged as a consumer reads it: which nodes exist, and whether
 * every reference lands on one of them. Not by what a piece returned.
 */
trait GraphAssertionsTrait {

  /**
   * Asserts every {"@id": …} reference in the graph names a node in it.
   *
   * A reference is an array whose only key is `@id`. Nodes carry `@type` as
   * well, so they are never mistaken for references.
   *
   * @param array $document
   *   The JSON-LD document, with `@graph`.
   */
  protected function assertGraphReferencesResolve(array $document): void {
    $ids = $this->graphIds($document);
    $dangling = [];
    $walk = function (mixed $value, string $path) use (&$walk, $ids, &$dangling): void {
      if (!is_array($value)) {
        return;
      }
      if (array_keys($value) === ['@id'] && !in_array($value['@id'], $ids, TRUE)) {
        $dangling[] = $path . ' → ' . $value['@id'];
        return;
      }
      foreach ($value as $key => $item) {
        $walk($item, $path . '.' . $key);
      }
    };
    foreach ($document['@graph'] as $index => $node) {
      $walk($node, implode('+', (array) ($node['@type'] ?? $index)));
    }
    $this->assertSame([], $dangling, 'Every @id reference resolves to a node in the graph.');
  }

  /**
   * Asserts the graph has exactly one node with this @id, and returns it.
   *
   * @param array $document
   *   The JSON-LD document.
   * @param string $id
   *   The @id.
   *
   * @return array
   *   The node.
   */
  protected function assertGraphNode(array $document, string $id): array {
    $nodes = array_values(array_filter($document['@graph'], static fn(array $node): bool => ($node['@id'] ?? NULL) === $id));
    $this->assertCount(1, $nodes, "Exactly one node has @id $id.");
    return $nodes[0];
  }

  /**
   * The @ids of the graph's nodes, in order.
   *
   * @param array $document
   *   The JSON-LD document.
   *
   * @return string[]
   *   The ids.
   */
  protected function graphIds(array $document): array {
    return array_values(array_filter(array_map(static fn(array $node): ?string => $node['@id'] ?? NULL, $document['@graph'])));
  }

}
