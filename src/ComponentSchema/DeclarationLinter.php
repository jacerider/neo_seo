<?php

declare(strict_types=1);

namespace Drupal\neo_seo\ComponentSchema;

/**
 * Checks a component's `seo` declaration against its definition.
 *
 * Static: nothing is rendered or resolved. It checks the shape (a list of
 * nodes, each with an @type), the directives, the syntax of every value, that
 * each path starts at something that exists — a prop, a row in scope, a
 * reserved root — and keys into what the prop's schema declares where it
 * declares it, that an `@each` names an array, and that each filter exists.
 */
class DeclarationLinter {

  /**
   * The findings for one definition.
   *
   * @param array $definition
   *   The component's definition.
   *
   * @return array<int, array{severity: 'error'|'warning', message: string}>
   *   Empty when the definition declares nothing, or declares it well.
   */
  public function lint(array $definition): array {
    if (!array_key_exists('seo', $definition)) {
      return [];
    }
    $findings = [];
    $declarations = $definition['seo'];
    if (!is_array($declarations) || !array_is_list($declarations)) {
      return [$this->error('`seo` must be a list of nodes, each with an `@type`.')];
    }
    $props = (array) ($definition['props']['properties'] ?? []);
    foreach ($declarations as $index => $declaration) {
      $label = sprintf('`seo` node %d', $index + 1);
      if (!is_array($declaration) || array_is_list($declaration)) {
        $findings[] = $this->error("$label is not a node (a mapping with an `@type`).");
        continue;
      }
      $this->node($declaration, $label, $props, [], TRUE, $findings);
    }
    return $findings;
  }

  /**
   * Checks a node and what it holds.
   *
   * @param array $node
   *   The node's declaration.
   * @param string $label
   *   Where it is, for messages.
   * @param array $props
   *   The component's props, by name.
   * @param array<string, array|null> $rows
   *   The rows in scope, by name, with their item schema where known.
   * @param bool $top
   *   Whether it is a top-level node.
   * @param array $findings
   *   Collects the findings.
   */
  protected function node(array $node, string $label, array $props, array $rows, bool $top, array &$findings): void {
    if ($top && !isset($node['@type'])) {
      $findings[] = $this->error("$label has no `@type`.");
    }
    if (isset($node['@type'])) {
      $label .= sprintf(' (%s)', is_string($node['@type']) ? $node['@type'] : 'list of types');
    }
    if (isset($node['@each'])) {
      try {
        $each = Expression::each($node['@each']);
        $schema = $this->path($each['path'], $props, $rows, "$label `@each`", $findings);
        if ($schema !== NULL && ($schema['type'] ?? NULL) !== 'array') {
          $findings[] = $this->warning(sprintf('%s `@each` repeats over `%s`, which is not an array prop.', $label, implode('.', $each['path'])));
        }
        if (in_array($each['name'], Expression::RESERVED, TRUE) || isset($props[$each['name']])) {
          $findings[] = $this->error(sprintf('%s `@each` names its rows `%s`, which is already a prop or a reserved name (graph, page, site).', $label, $each['name']));
        }
        $rows[$each['name']] = is_array($schema['items'] ?? NULL) ? $schema['items'] : NULL;
      }
      catch (\InvalidArgumentException $e) {
        $findings[] = $this->error("$label: " . $e->getMessage());
      }
    }
    if (isset($node['@if'])) {
      try {
        $condition = Expression::condition($node['@if']);
        $this->expression($condition['expression'], $props, $rows, "$label `@if`", $findings);
      }
      catch (\InvalidArgumentException $e) {
        $findings[] = $this->error("$label: " . $e->getMessage());
      }
    }
    if (isset($node['@required'])) {
      $required = $node['@required'];
      if (!is_array($required) || array_filter($required, fn($property) => !is_string($property))) {
        $findings[] = $this->error("$label `@required` must be a list of property names.");
      }
      else {
        foreach (array_diff($required, array_keys($node)) as $property) {
          $findings[] = $this->warning(sprintf('%s requires `%s`, which it does not declare: it would never be printed.', $label, $property));
        }
      }
    }
    foreach ($node as $key => $value) {
      if (is_string($key) && str_starts_with($key, '@') && !in_array($key, [...Expression::DIRECTIVES, '@type', '@id'], TRUE)) {
        $findings[] = $this->error(sprintf('%s has an unknown directive `%s`. Directives: @each, @if, @required; @type and @id are printed.', $label, $key));
        continue;
      }
      if (in_array($key, Expression::DIRECTIVES, TRUE)) {
        continue;
      }
      $this->value($value, sprintf('%s `%s`', $label, $key), $props, $rows, $findings);
    }
  }

  /**
   * Checks a property's value.
   */
  protected function value(mixed $value, string $label, array $props, array $rows, array &$findings): void {
    if (is_string($value)) {
      foreach (Expression::placeholders($value) as $expression) {
        $this->expression($expression, $props, $rows, $label, $findings);
      }
      return;
    }
    if (!is_array($value)) {
      return;
    }
    if (!array_is_list($value)) {
      $this->node($value, $label, $props, $rows, FALSE, $findings);
      return;
    }
    foreach ($value as $item) {
      $this->value($item, $label, $props, $rows, $findings);
    }
  }

  /**
   * Checks one expression: its syntax, its path, its filters.
   */
  protected function expression(string $expression, array $props, array $rows, string $label, array &$findings): void {
    try {
      $parsed = Expression::parse($expression);
    }
    catch (\InvalidArgumentException $e) {
      $findings[] = $this->error("$label: " . $e->getMessage());
      return;
    }
    $this->path($parsed['path'], $props, $rows, $label, $findings);
    foreach ($parsed['filters'] as $filter) {
      if (!in_array($filter['name'], Filters::NAMES, TRUE)) {
        $findings[] = $this->error(sprintf('%s uses an unknown filter `%s`. Filters: %s.', $label, $filter['name'], implode(', ', Filters::NAMES)));
      }
    }
  }

  /**
   * Checks a path, and returns the schema it reads where that is known.
   *
   * @return array|null
   *   The schema of what the path reads, or NULL when unknown.
   */
  protected function path(array $path, array $props, array $rows, string $label, array &$findings): ?array {
    $root = (string) $path[0];
    $dotted = implode('.', $path);
    if (array_key_exists($root, $rows)) {
      $schema = $rows[$root];
    }
    elseif (in_array($root, Expression::RESERVED, TRUE)) {
      $allowed = ['graph' => Expression::GRAPH, 'page' => Expression::PAGE, 'site' => Expression::SITE][$root];
      if (count($path) !== 2 || !in_array($path[1], $allowed, TRUE)) {
        $findings[] = $this->error(sprintf('%s reads `%s`; `%s` offers %s.', $label, $dotted, $root, implode(', ', array_map(fn($key) => "$root.$key", $allowed))));
      }
      return NULL;
    }
    elseif (isset($props[$root])) {
      $schema = is_array($props[$root]) ? $props[$root] : NULL;
    }
    else {
      $findings[] = $this->error(sprintf('%s reads `%s`, but `%s` is not a prop of the component%s.', $label, $dotted, $root, $rows ? ' or a row in scope (' . implode(', ', array_keys($rows)) . ')' : ''));
      return NULL;
    }
    // Into the value, as far as the schema says what is there.
    foreach (array_slice($path, 1) as $segment) {
      if ($schema === NULL) {
        return NULL;
      }
      if (ctype_digit((string) $segment)) {
        $schema = is_array($schema['items'] ?? NULL) ? $schema['items'] : NULL;
        continue;
      }
      $properties = $schema['properties'] ?? NULL;
      if (($schema['type'] ?? NULL) !== 'object' || !is_array($properties)) {
        return NULL;
      }
      if (!isset($properties[$segment])) {
        $findings[] = $this->warning(sprintf('%s reads `%s`, but `%s` declares no `%s`.', $label, $dotted, $root, $segment));
        return NULL;
      }
      $schema = is_array($properties[$segment]) ? $properties[$segment] : NULL;
    }
    return $schema;
  }

  /**
   * An error finding.
   */
  protected function error(string $message): array {
    return ['severity' => 'error', 'message' => 'SEO: ' . $message];
  }

  /**
   * A warning finding.
   */
  protected function warning(string $message): array {
    return ['severity' => 'warning', 'message' => 'SEO: ' . $message];
  }

}
