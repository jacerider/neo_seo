<?php

declare(strict_types=1);

namespace Drupal\neo_seo\ComponentSchema;

use Drupal\neo_seo\Graph\PageContext;
use Drupal\neo_seo\Graph\SchemaIds;

/**
 * Builds the nodes a component's `seo` declaration describes.
 *
 * The declaration is shaped like the JSON-LD it produces. Each value is read
 * as Expression describes; each node may carry three directives:
 * - `@each: <list> as <name>` — one node per row of the list (at the top) or
 *   a list of nodes (as a property's value), the row readable by name;
 * - `@if: '{{ path }}'` or `'not {{ path }}'` — the node only when the value
 *   is truthy (or not);
 * - `@required: [property, …]` — the node only when it has them. A top-level
 *   node is checked by the caller, which reports what it drops; a nested one
 *   is simply left out.
 *
 * What is empty is pruned: an empty value, then an object left with nothing
 * but its @type and @id. An `@id` is made absolute against the page:
 * `#office-plano` is `{page}#office-plano`.
 */
class Evaluator {

  /**
   * Constructs the evaluator.
   *
   * @param \Drupal\neo_seo\ComponentSchema\Filters $filters
   *   The filters.
   * @param \Drupal\neo_seo\Graph\SchemaIds $ids
   *   The ids of the graph's own nodes, for `graph.*`.
   */
  public function __construct(
    protected Filters $filters,
    protected SchemaIds $ids,
  ) {}

  /**
   * The top-level nodes of a declaration.
   *
   * @param array $declarations
   *   The definition's `seo` list.
   * @param \Closure $prop
   *   Resolves a prop of the component by name: fn(string): mixed. Called at
   *   most once per prop, and only for props the declaration reads.
   * @param \Drupal\neo_seo\Graph\PageContext $context
   *   The page.
   *
   * @return array<int, array{node: array, index: int|null, required: string[]}>
   *   Each node, with its row number (from 1) when it came from an `@each`,
   *   and the properties its declaration requires.
   */
  public function evaluate(array $declarations, \Closure $prop, PageContext $context): array {
    $scope = new Scope($prop, $context, $this->ids);
    $nodes = [];
    foreach ($declarations as $declaration) {
      if (!is_array($declaration) || array_is_list($declaration)) {
        continue;
      }
      $required = array_values(array_filter((array) ($declaration['@required'] ?? []), 'is_string'));
      foreach ($this->expand($declaration, $scope) as [$node, $index]) {
        $nodes[] = ['node' => $node, 'index' => $index, 'required' => $required];
      }
    }
    return $nodes;
  }

  /**
   * A node declaration as nodes: one, or one per row of its `@each`.
   *
   * @return array<int, array{0: array, 1: int|null}>
   *   Each node, and its row number.
   */
  protected function expand(array $declaration, Scope $scope, bool $nested = FALSE): array {
    if (!isset($declaration['@each'])) {
      $node = $this->build($declaration, $scope, $nested);
      return $node === NULL ? [] : [[$node, NULL]];
    }
    try {
      $each = Expression::each($declaration['@each']);
    }
    catch (\InvalidArgumentException) {
      return [];
    }
    $list = $scope->resolve($each['path']);
    if (!is_iterable($list)) {
      return [];
    }
    $nodes = [];
    $index = 0;
    foreach ($list as $row) {
      $index++;
      $node = $this->build($declaration, $scope->with($each['name'], $row), $nested);
      if ($node !== NULL) {
        $nodes[] = [$node, $index];
      }
    }
    return $nodes;
  }

  /**
   * One node: its condition, its values, pruned.
   */
  protected function build(array $declaration, Scope $scope, bool $nested): ?array {
    if (isset($declaration['@if']) && !$this->holds($declaration['@if'], $scope)) {
      return NULL;
    }
    $node = [];
    foreach ($declaration as $key => $value) {
      if (in_array($key, Expression::DIRECTIVES, TRUE)) {
        continue;
      }
      $value = $this->value($value, $scope);
      if ($key === '@id' && is_string($value)) {
        $value = $this->id($value, $scope->context);
      }
      if (!static::isEmpty($value)) {
        $node[(string) $key] = $value;
      }
    }
    if (!array_diff_key($node, ['@type' => TRUE, '@id' => TRUE])) {
      return NULL;
    }
    if ($nested && array_diff((array) ($declaration['@required'] ?? []), array_keys($node))) {
      return NULL;
    }
    return $node;
  }

  /**
   * A declared value, evaluated.
   */
  protected function value(mixed $value, Scope $scope): mixed {
    if (is_string($value)) {
      return $this->text($value, $scope);
    }
    if (!is_array($value)) {
      return $value;
    }
    if (!array_is_list($value)) {
      // An object: a list of objects when it repeats.
      if (isset($value['@each'])) {
        return array_map(fn(array $built) => $built[0], $this->expand($value, $scope, TRUE));
      }
      return $this->build($value, $scope, TRUE);
    }
    $items = [];
    foreach ($value as $item) {
      if (is_array($item) && !array_is_list($item) && isset($item['@each'])) {
        foreach ($this->expand($item, $scope, TRUE) as [$node]) {
          $items[] = $node;
        }
        continue;
      }
      $item = $this->value($item, $scope);
      if (!static::isEmpty($item)) {
        $items[] = $item;
      }
    }
    return $items;
  }

  /**
   * A string value: literal, one placeholder (typed), or text with some.
   */
  protected function text(string $value, Scope $scope): mixed {
    $whole = Expression::whole($value);
    if ($whole !== NULL) {
      return static::normalize($this->expression($whole, $scope));
    }
    if (!Expression::placeholders($value)) {
      return $value;
    }
    // Text with placeholders is empty when every placeholder is: "#office-"
    // is no id, and ", " no address.
    $filled = FALSE;
    $text = Expression::interpolate($value, function (string $expression) use ($scope, &$filled): string {
      $part = static::normalize($this->expression($expression, $scope));
      $part = is_scalar($part) && !is_bool($part) ? trim((string) $part) : '';
      $filled = $filled || $part !== '';
      return $part;
    });
    return $filled ? trim((string) preg_replace('/\s{2,}/u', ' ', $text)) : NULL;
  }

  /**
   * An expression's value: its path resolved, its filters applied.
   */
  protected function expression(string $expression, Scope $scope): mixed {
    try {
      $parsed = Expression::parse($expression);
    }
    catch (\InvalidArgumentException) {
      return NULL;
    }
    $value = $scope->resolve($parsed['path']);
    foreach ($parsed['filters'] as $filter) {
      try {
        $value = $this->filters->apply($filter['name'], $value, $filter['arg'], $scope->context);
      }
      catch (\InvalidArgumentException) {
        return NULL;
      }
    }
    return $value;
  }

  /**
   * Whether an `@if` holds.
   */
  protected function holds(mixed $condition, Scope $scope): bool {
    try {
      $parsed = Expression::condition($condition);
    }
    catch (\InvalidArgumentException) {
      return FALSE;
    }
    $value = static::normalize($this->expression($parsed['expression'], $scope));
    $truthy = !static::isEmpty($value) && $value !== FALSE && $value !== 0 && $value !== '0';
    return $parsed['negate'] ? !$truthy : $truthy;
  }

  /**
   * An @id against the page: a fragment is the page's.
   */
  protected function id(string $id, PageContext $context): string {
    return preg_match('#^[a-z][a-z0-9+.-]*://#i', $id) ? $id : $context->canonicalUrl . '#' . ltrim($id, '#');
  }

  /**
   * A value as JSON can carry it: text for what can be text, no objects.
   */
  public static function normalize(mixed $value): mixed {
    if ($value instanceof \Stringable) {
      $value = (string) $value;
    }
    if (is_string($value)) {
      return trim($value);
    }
    if (is_array($value)) {
      $normalized = [];
      foreach ($value as $key => $item) {
        $item = static::normalize($item);
        if (!static::isEmpty($item)) {
          $normalized[$key] = $item;
        }
      }
      return array_is_list($value) ? array_values($normalized) : $normalized;
    }
    return is_scalar($value) ? $value : NULL;
  }

  /**
   * Whether a value is nothing to print.
   */
  public static function isEmpty(mixed $value): bool {
    return $value === NULL || $value === '' || $value === [];
  }

}
