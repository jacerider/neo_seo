<?php

declare(strict_types=1);

namespace Drupal\neo_seo_commerce\Plugin\GraphPiece;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_seo\Attribute\GraphPiece;
use Drupal\neo_seo\Graph\GraphPieceBase;
use Drupal\neo_seo\Graph\PageContext;
use Drupal\neo_seo\Graph\SchemaIds;
use Drupal\neo_seo\Mapping\BundleMappingRepository;
use Drupal\neo_seo\SeoOverrides;
use Drupal\neo_seo_commerce\ProductSchema;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A product page's Product, with an Offer.
 *
 * The WebPage's `mainEntity` (ProductGraphHooks). Only on a product's own
 * page, and only while it has a variation a visitor can buy.
 */
#[GraphPiece(
  id: 'product',
  label: new TranslatableMarkup('Product'),
  weight: -60,
)]
class Product extends GraphPieceBase {

  /**
   * The fragment of the product's @id.
   */
  public const FRAGMENT = 'product';

  /**
   * The product schema service.
   */
  protected ProductSchema $products;

  /**
   * The bundle mapping repository, for the product type's image field.
   */
  protected BundleMappingRepository $mapping;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->products = $container->get(ProductSchema::class);
    $instance->mapping = $container->get(BundleMappingRepository::class);
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function isNeeded(PageContext $context): bool {
    return $context->entity instanceof ProductInterface && $this->products->activeVariations($context->entity, $context);
  }

  /**
   * {@inheritdoc}
   */
  public function generate(PageContext $context): array {
    /** @var \Drupal\commerce_product\Entity\ProductInterface $product */
    $product = $context->entity;
    $values = $this->headValues->resolve($context);
    $variations = $this->products->activeVariations($product, $context);
    $variation = reset($variations);
    return [
      [
        '@type' => 'Product',
        '@id' => $this->ids->pageScoped($context, static::FRAGMENT),
        'name' => $context->title,
        'description' => $values->get('description'),
        'image' => $this->ownImage($context) ?? $this->products->variationImage($variation, $context),
        'sku' => $variation->getSku(),
        'brand' => $this->products->brand($product, $context),
        'url' => $context->canonicalUrl,
        'mainEntityOfPage' => SchemaIds::ref($this->ids->webPage($context)),
        'offers' => $this->products->offer($variation, $context),
      ],
    ];
  }

  /**
   * The page's primary image, when it is the product's own.
   *
   * The editor's share image or the product type's mapped image field. The
   * image template's fallback (a site-wide image) does not picture this
   * product, so a variation's image is used instead.
   *
   * @return array{"@id": string}|null
   *   A reference to the primary image, or NULL.
   */
  protected function ownImage(PageContext $context): ?array {
    $own = SeoOverrides::of($context->entity)['image'] || $this->mapping->image($context);
    return $own && $this->headValues->resolve($context)->image() ? SchemaIds::ref($this->ids->primaryImage($context)) : NULL;
  }

}
