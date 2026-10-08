<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Head;

use Drupal\Core\Plugin\PluginBase;

/**
 * Base class for head tags, with builders for the common elements.
 */
abstract class HeadTagBase extends PluginBase implements HeadTagInterface {

  /**
   * A `<meta name="…" content="…">` element, or nothing for an empty value.
   *
   * @param string $name
   *   The name attribute.
   * @param string|int|null $content
   *   The content.
   * @param string $attribute
   *   The attribute that names the tag: 'name', or 'property' for Open Graph.
   *
   * @return array<int, array{0: array, 1: string}>
   *   Zero or one html_head attachment.
   */
  protected function meta(string $name, string|int|null $content, string $attribute = 'name'): array {
    if ($content === NULL || $content === '') {
      return [];
    }
    return [
      [
        [
          '#type' => 'html_tag',
          '#tag' => 'meta',
          '#attributes' => [$attribute => $name, 'content' => (string) $content],
        ],
        'neo_seo_' . preg_replace('/[^a-z0-9_]+/', '_', strtolower($name)),
      ],
    ];
  }

}
