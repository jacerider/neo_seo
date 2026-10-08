<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Head;

use Drupal\neo_seo\Graph\PageContext;

/**
 * A page's resolved head values: what every head tag prints from.
 *
 * Every template field has been chosen from its layers and had its tokens
 * replaced. The image is resolved separately because it carries more than a
 * string: a URL, its dimensions and its alt text.
 */
final class HeadValues {

  /**
   * Constructs the head values.
   *
   * @param \Drupal\neo_seo\Graph\PageContext $context
   *   The page.
   * @param array<string, string> $fields
   *   Resolved template fields, keyed by field name.
   * @param array{url: string, width: ?int, height: ?int, alt: ?string}|null $image
   *   The share image, if any.
   * @param string[] $layers
   *   The ids of the defaults that applied, lowest precedence first.
   */
  public function __construct(
    public readonly PageContext $context,
    private readonly array $fields,
    private readonly ?array $image = NULL,
    public readonly array $layers = [],
  ) {}

  /**
   * A resolved field, '' when it is not set.
   */
  public function get(string $field): string {
    return $this->fields[$field] ?? '';
  }

  /**
   * Every resolved field.
   *
   * @return array<string, string>
   *   The fields.
   */
  public function all(): array {
    return $this->fields;
  }

  /**
   * The share image.
   *
   * @return array{url: string, width: ?int, height: ?int, alt: ?string}|null
   *   The image, or NULL when the page has none.
   */
  public function image(): ?array {
    return $this->image;
  }

}
