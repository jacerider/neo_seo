<?php

declare(strict_types=1);

namespace Drupal\neo_seo\ComponentSchema;

use Drupal\neo_seo\Graph\PageContext;
use Drupal\neo_seo\Graph\SchemaIds;

/**
 * What a declaration's paths can read.
 *
 * In order: a row an `@each` named (the innermost first), the reserved roots
 * (graph, loop, page, site), then the component's props — each resolved
 * once, when first read. A path then keys into the value, a segment at a
 * time.
 */
final class Scope {

  /**
   * The props resolved so far, by name.
   *
   * Shared by every scope derived from this one.
   *
   * @var \ArrayObject<string, mixed>
   */
  protected \ArrayObject $props;

  /**
   * Constructs the scope.
   *
   * @param \Closure $prop
   *   Resolves a prop by name.
   * @param \Drupal\neo_seo\Graph\PageContext $context
   *   The page.
   * @param \Drupal\neo_seo\Graph\SchemaIds $ids
   *   The graph's ids.
   * @param array<string, mixed> $rows
   *   The rows in scope, by name.
   * @param int|null $position
   *   The innermost row's position, from 1; NULL outside an `@each`.
   */
  public function __construct(
    protected \Closure $prop,
    public readonly PageContext $context,
    protected SchemaIds $ids,
    protected array $rows = [],
    protected ?int $position = NULL,
  ) {
    $this->props = new \ArrayObject();
  }

  /**
   * This scope with one more row, by name, at its position.
   */
  public function with(string $name, mixed $row, int $position): self {
    $scope = clone $this;
    $scope->rows[$name] = $row;
    $scope->position = $position;
    return $scope;
  }

  /**
   * The value a path reads, or NULL.
   *
   * @param string[] $path
   *   The path's segments.
   */
  public function resolve(array $path): mixed {
    $root = (string) array_shift($path);
    if (array_key_exists($root, $this->rows)) {
      $value = $this->rows[$root];
    }
    elseif (in_array($root, Expression::RESERVED, TRUE)) {
      return $this->reserved($root, $path);
    }
    else {
      if (!$this->props->offsetExists($root)) {
        $this->props[$root] = ($this->prop)($root);
      }
      $value = $this->props[$root];
    }
    foreach ($path as $segment) {
      if (is_array($value) && array_key_exists($segment, $value)) {
        $value = $value[$segment];
      }
      elseif ($value instanceof \ArrayAccess && $value->offsetExists($segment)) {
        $value = $value[$segment];
      }
      else {
        return NULL;
      }
    }
    return $value;
  }

  /**
   * A reserved root's value: a reference (graph.*), or a plain value.
   */
  protected function reserved(string $root, array $path): mixed {
    $key = (string) ($path[0] ?? '');
    if (count($path) !== 1) {
      return NULL;
    }
    return match ($root) {
      'graph' => match ($key) {
        'organization' => SchemaIds::ref($this->ids->siteIdentity($this->context)),
        'website' => SchemaIds::ref($this->ids->website($this->context)),
        'page' => SchemaIds::ref($this->ids->webPage($this->context)),
        default => NULL,
      },
      'loop' => $key === 'position' ? $this->position : NULL,
      'page' => match ($key) {
        'url' => $this->context->canonicalUrl,
        'title' => $this->context->title,
        'language' => $this->context->langcode,
        default => NULL,
      },
      'site' => match ($key) {
        'name' => $this->context->siteName,
        'url' => $this->context->siteUrl,
        default => NULL,
      },
      default => NULL,
    };
  }

}
