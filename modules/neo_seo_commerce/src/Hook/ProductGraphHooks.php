<?php

declare(strict_types=1);

namespace Drupal\neo_seo_commerce\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\neo_seo\Graph\PageContext;
use Drupal\neo_seo\Graph\SchemaIds;
use Drupal\neo_seo_commerce\Plugin\GraphPiece\Product;

/**
 * The product is what a product page is about.
 */
class ProductGraphHooks {

  /**
   * Constructs the hooks.
   *
   * @param \Drupal\neo_seo\Graph\SchemaIds $ids
   *   The id service.
   */
  public function __construct(
    protected SchemaIds $ids,
  ) {}

  /**
   * Implements hook_neo_seo_graph_alter().
   *
   * Makes the product the WebPage's `mainEntity`, as a person is on a profile
   * page. neo_seo's WebPage piece knows nothing of products, so the link is
   * made here, once both nodes are in the graph.
   */
  #[Hook('neo_seo_graph_alter')]
  public function graphAlter(array &$nodes, PageContext $context): void {
    $productId = $this->ids->pageScoped($context, Product::FRAGMENT);
    if (!in_array($productId, array_column($nodes, '@id'), TRUE)) {
      return;
    }
    foreach ($nodes as &$node) {
      if (($node['@id'] ?? NULL) === $this->ids->webPage($context) && empty($node['mainEntity'])) {
        $node['mainEntity'] = SchemaIds::ref($productId);
      }
    }
  }

}
