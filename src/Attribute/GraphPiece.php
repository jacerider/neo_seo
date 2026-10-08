<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Declares a graph piece: one contributor to a page's schema.org @graph.
 *
 * Pieces are discovered under `Plugin/GraphPiece` in any module, so a site
 * module adds schema (wps's JobPosting) without touching neo_seo.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class GraphPiece extends Plugin {

  /**
   * Constructs a GraphPiece attribute.
   *
   * @param string $id
   *   The plugin id.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $label
   *   The human-readable label.
   * @param int $weight
   *   The order the piece's nodes take in the graph. Lower comes first. The
   *   graph is a set, so order changes nothing a consumer reads; it only keeps
   *   the output stable and readable (site identity first, the page after).
   * @param class-string|null $deriver
   *   (optional) The deriver class.
   */
  public function __construct(
    public readonly string $id,
    public readonly ?TranslatableMarkup $label = NULL,
    public readonly int $weight = 0,
    public readonly ?string $deriver = NULL,
  ) {}

}
