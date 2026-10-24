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
 * A product page's Product with an Offer, or ProductGroup with variants.
 *
 * One active variation is a Product. Several are a ProductGroup in Google's
 * variant form: `variesBy` the schema.org properties whose values differ
 * between them, and each a Product in `hasVariant` with its own SKU, `?v=`
 * URL and Offer. Either is the WebPage's `mainEntity` (ProductGraphHooks).
 * Only on a product's own page, and only while it has a variation a visitor
 * can buy.
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
   * Google's age groups, as the age range a QuantitativeValue states.
   */
  protected const AGE_GROUPS = [
    'newborn' => ['maxValue' => 3, 'unitCode' => 'MON'],
    'infant' => ['minValue' => 3, 'maxValue' => 12, 'unitCode' => 'MON'],
    'toddler' => ['minValue' => 1, 'maxValue' => 5, 'unitCode' => 'ANN'],
    'kids' => ['minValue' => 5, 'maxValue' => 13, 'unitCode' => 'ANN'],
    'adult' => ['minValue' => 13, 'unitCode' => 'ANN'],
  ];

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
    $node = [
      '@type' => 'Product',
      '@id' => $this->ids->pageScoped($context, static::FRAGMENT),
      'name' => $context->title,
      'description' => $values->get('description'),
      'image' => $this->ownImage($context),
      'brand' => $this->products->brand($product, $context),
      'url' => $context->canonicalUrl,
      'mainEntityOfPage' => SchemaIds::ref($this->ids->webPage($context)),
    ];
    if (count($variations) === 1) {
      $variation = reset($variations);
      $node['image'] ??= $this->products->variationImage($variation, $context);
      $node['sku'] = $variation->getSku();
      $node['offers'] = $this->products->offer($variation, $context);
      return [$this->withProperties($node, $this->products->variantProperties($variation, $context))];
    }

    $variants = [];
    $properties = [];
    foreach ($variations as $variation) {
      $properties[$variation->id()] = $this->products->variantProperties($variation, $context);
      $variants[] = $this->withProperties([
        '@type' => 'Product',
        '@id' => $this->ids->pageScoped($context, static::FRAGMENT . '-' . $variation->id()),
        'name' => $variation->getTitle() ?: $context->title,
        'sku' => $variation->getSku(),
        'image' => $this->products->variationImage($variation, $context),
        'url' => $this->products->variationUrl($variation, $context),
        'offers' => $this->products->offer($variation, $context),
      ], $properties[$variation->id()]);
    }
    $variesBy = [];
    foreach (array_keys(ProductSchema::VARIES_BY) as $property) {
      $seen = array_unique(array_map(static fn(array $set): string => $set[$property] ?? '', $properties));
      if (count($seen) > 1) {
        $variesBy[] = 'https://schema.org/' . $property;
      }
    }
    return [
      array_merge($node, [
        '@type' => 'ProductGroup',
        'productGroupID' => (string) $product->id(),
        'variesBy' => $variesBy,
        'hasVariant' => $variants,
      ]),
    ];
  }

  /**
   * Adds a variant's schema.org properties to its Product.
   *
   * Color, size, material and pattern are the Product's own. Age and gender
   * describe who it is for, so they go on its audience, as Google reads them.
   *
   * @param array<string, mixed> $node
   *   The Product.
   * @param array<string, string> $properties
   *   Values keyed by schema.org property.
   *
   * @return array<string, mixed>
   *   The Product.
   */
  protected function withProperties(array $node, array $properties): array {
    foreach ($properties as $property => $value) {
      if ($property === 'suggestedGender') {
        $node['audience']['suggestedGender'] = strtolower($value);
      }
      elseif ($property === 'suggestedAge') {
        $node['audience']['suggestedAge'] = ['@type' => 'QuantitativeValue']
          + (static::AGE_GROUPS[strtolower($value)] ?? ['name' => $value]);
      }
      else {
        $node[$property] = $value;
      }
    }
    if (isset($node['audience'])) {
      $node['audience'] = ['@type' => 'PeopleAudience'] + $node['audience'];
    }
    return $node;
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
