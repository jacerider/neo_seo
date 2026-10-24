<?php

declare(strict_types=1);

namespace Drupal\neo_seo\ComponentSchema;

/**
 * The value syntax of a component's `seo` declaration.
 *
 * A value is literal unless it holds a placeholder, `{{ path|filter }}`. A
 * value that is one placeholder and nothing else keeps the type of what it
 * reads (a number, a list, an object); placeholders inside other text are
 * filled in as text. A path names a prop of the component, a row an `@each`
 * named, or one of the reserved roots — graph, loop, page, site — then keys
 * into the value, a segment at a time. Filters follow, each optionally with one
 * quoted argument: `{{ tags|join(' / ') }}`.
 *
 * Two directives have syntax of their own: `'@each': <path> as <name>`, and
 * `'@if': '{{ path }}'` or `'@if': 'not {{ path }}'`.
 */
final class Expression {

  /**
   * The roots a path may start with besides props and rows.
   */
  public const RESERVED = ['graph', 'loop', 'page', 'site'];

  /**
   * The nodes `graph.*` refers to.
   */
  public const GRAPH = ['organization', 'website', 'page'];

  /**
   * The values `loop.*`, `page.*` and `site.*` read.
   *
   * `loop.position` is the place, from 1, of the row the innermost `@each`
   * is building among the nodes it prints.
   */
  public const LOOP = ['position'];
  public const PAGE = ['url', 'title', 'language'];
  public const SITE = ['name', 'url'];

  /**
   * The directives a node may carry; every other `@` key is printed.
   */
  public const DIRECTIVES = ['@each', '@if', '@required'];

  /**
   * One placeholder.
   */
  protected const PLACEHOLDER = '/\{\{(.*?)\}\}/s';

  /**
   * A path segment: a name, or a list index.
   */
  protected const SEGMENT = '(?:[A-Za-z_][A-Za-z0-9_-]*|\d+)';

  /**
   * The expression inside a value that is one placeholder, else NULL.
   */
  public static function whole(string $value): ?string {
    return preg_match('/^\s*\{\{(.*?)\}\}\s*$/s', $value, $match) && !str_contains($match[1], '{{')
      ? trim($match[1])
      : NULL;
  }

  /**
   * The expressions of every placeholder in a value.
   *
   * @return string[]
   *   In order.
   */
  public static function placeholders(string $value): array {
    preg_match_all(static::PLACEHOLDER, $value, $matches);
    return array_map('trim', $matches[1]);
  }

  /**
   * Fills a value's placeholders with the text each callback returns.
   */
  public static function interpolate(string $value, callable $fill): string {
    return (string) preg_replace_callback(static::PLACEHOLDER, fn(array $match) => (string) $fill(trim($match[1])), $value);
  }

  /**
   * An expression's path and filters.
   *
   * @return array{path: string[], filters: array<int, array{name: string, arg: ?string}>}
   *   The path's segments, and the filters in order.
   *
   * @throws \InvalidArgumentException
   *   When the expression is not a path with filters.
   */
  public static function parse(string $expression): array {
    $segment = static::SEGMENT;
    $pattern = "/^(?<path>$segment(?:\\.$segment)*)(?<filters>(?:\\s*\\|\\s*[a-z_]+(?:\\(\\s*(?:'[^']*'|\"[^\"]*\")\\s*\\))?)*)\\s*$/";
    if (!preg_match($pattern, trim($expression), $match)) {
      throw new \InvalidArgumentException(sprintf('`{{ %s }}` is not a path with filters, e.g. `{{ office.address|postal_address }}`.', trim($expression)));
    }
    preg_match_all("/\\|\\s*(?<name>[a-z_]+)(?:\\(\\s*(?:'(?<single>[^']*)'|\"(?<double>[^\"]*)\")\\s*\\))?/", $match['filters'], $filters, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
    return [
      'path' => explode('.', $match['path']),
      'filters' => array_map(fn(array $filter) => [
        'name' => (string) $filter['name'],
        'arg' => $filter['single'] ?? $filter['double'] ?? NULL,
      ], $filters),
    ];
  }

  /**
   * An `@each`: the list to repeat over, and the name a row takes.
   *
   * @return array{path: string[], name: string}
   *   The list's path and the row's name.
   *
   * @throws \InvalidArgumentException
   *   When it is not `<path> as <name>`.
   */
  public static function each(mixed $value): array {
    $segment = static::SEGMENT;
    if (!is_string($value) || !preg_match("/^\\s*(?:\\{\\{\\s*)?(?<path>$segment(?:\\.$segment)*)(?:\\s*\\}\\})?\\s+as\\s+(?<name>[A-Za-z_][A-Za-z0-9_]*)\\s*$/", $value, $match)) {
      throw new \InvalidArgumentException(sprintf('`@each: %s` is not `<list> as <name>`, e.g. `locations as office`.', is_scalar($value) ? (string) $value : gettype($value)));
    }
    return ['path' => explode('.', $match['path']), 'name' => $match['name']];
  }

  /**
   * An `@if`: the expression it tests, and whether it is negated.
   *
   * @return array{expression: string, negate: bool}
   *   The expression inside the placeholder.
   *
   * @throws \InvalidArgumentException
   *   When it is not `{{ path }}` or `not {{ path }}`.
   */
  public static function condition(mixed $value): array {
    $negate = FALSE;
    if (is_string($value) && preg_match('/^\s*not\s+(.*)$/s', $value, $match)) {
      $negate = TRUE;
      $value = $match[1];
    }
    $expression = is_string($value) ? static::whole($value) : NULL;
    if ($expression === NULL) {
      throw new \InvalidArgumentException(sprintf('`@if: %s` is not `{{ path }}` or `not {{ path }}`.', is_scalar($value) ? (string) $value : gettype($value)));
    }
    return ['expression' => $expression, 'negate' => $negate];
  }

}
