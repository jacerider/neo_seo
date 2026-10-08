<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Declares a head tag: something neo_seo prints in the page's <head>.
 *
 * A tag may declare template fields — the values a site builder sets as token
 * templates per entity type and bundle, and an editor may override per page.
 * Fields are shared: the description field feeds the meta description, the
 * Open Graph description and the Twitter description alike.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class HeadTag extends Plugin {

  /**
   * Constructs a HeadTag attribute.
   *
   * @param string $id
   *   The plugin id.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $label
   *   The human-readable label.
   * @param int $weight
   *   The order tags print in.
   * @param array<string, \Drupal\Core\StringTranslation\TranslatableMarkup> $fields
   *   The template fields this tag declares, keyed by field name.
   * @param class-string|null $deriver
   *   (optional) The deriver class.
   */
  public function __construct(
    public readonly string $id,
    public readonly ?TranslatableMarkup $label = NULL,
    public readonly int $weight = 0,
    public readonly array $fields = [],
    public readonly ?string $deriver = NULL,
  ) {}

}
