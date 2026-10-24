<?php

declare(strict_types=1);

namespace Drupal\neo_seo_commerce;

use Drupal\commerce\Context;
use Drupal\commerce_price\Resolver\ChainPriceResolverInterface;
use Drupal\commerce_price\RounderInterface;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\commerce_store\Resolver\StoreResolverInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\Entity\ThirdPartySettingsInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\media\MediaInterface;
use Drupal\neo_seo\Graph\PageContext;
use Drupal\neo_seo\Graph\SchemaIds;
use Drupal\neo_seo\MediaImage;
use Drupal\neo_seo_commerce\Hook\ProductTypeHooks;
use Drupal\taxonomy\TermInterface;

/**
 * What a product page says about its product: variations, offers and brand.
 *
 * Everything is read as an anonymous visitor in the default store sees it, so
 * the graph is the same for everyone and agrees with the page a search engine
 * fetches.
 */
class ProductSchema {

  /**
   * Entity types whose entities change what a visitor pays.
   *
   * Their list cache tags are recorded wherever they exist, so a new or
   * edited promotion or price list reaches the graph.
   */
  protected const PRICING_ENTITY_TYPES = [
    'commerce_promotion',
    'commerce_pricelist',
    'commerce_pricelist_item',
  ];

  /**
   * Constructs the product schema service.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\commerce_price\Resolver\ChainPriceResolverInterface $priceResolver
   *   Commerce's chain price resolver.
   * @param \Drupal\commerce_store\Resolver\StoreResolverInterface $storeResolver
   *   Commerce's default store resolver.
   * @param \Drupal\commerce_price\RounderInterface $rounder
   *   The price rounder.
   * @param \Drupal\neo_seo\Graph\SchemaIds $ids
   *   The id service.
   * @param \Drupal\neo_seo\MediaImage $mediaImage
   *   The media image helper, for a variation's image.
   * @param object|null $stock
   *   The stock service manager of commerce_stock, where it is installed.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ChainPriceResolverInterface $priceResolver,
    protected StoreResolverInterface $storeResolver,
    protected RounderInterface $rounder,
    protected SchemaIds $ids,
    protected MediaImage $mediaImage,
    protected ?object $stock = NULL,
  ) {}

  /**
   * The product's active variations: those a visitor can buy, in order.
   *
   * Published ones, whoever is looking, so an editor sees the graph a
   * visitor gets.
   *
   * @return \Drupal\commerce_product\Entity\ProductVariationInterface[]
   *   The variations.
   */
  public function activeVariations(ProductInterface $product, PageContext $context): array {
    $variations = [];
    foreach ($product->getVariations() as $variation) {
      $context->addCacheableDependency($variation);
      if ($variation->isPublished()) {
        $variations[] = $variation;
      }
    }
    return $variations;
  }

  /**
   * A variation's Offer: what an anonymous visitor pays in the default store.
   *
   * @return array<string, mixed>|null
   *   The Offer, or NULL when there is no default store or no price.
   */
  public function offer(ProductVariationInterface $variation, PageContext $context): ?array {
    $store = $this->storeResolver->resolve();
    if (!$store) {
      return NULL;
    }
    $context->addCacheableDependency($store);
    $tags = [];
    foreach (static::PRICING_ENTITY_TYPES as $entityTypeId) {
      $entityType = $this->entityTypeManager->getDefinition($entityTypeId, FALSE);
      $tags = [...$tags, ...($entityType?->getListCacheTags() ?? [])];
    }
    $context->addCacheableDependency((new CacheableMetadata())->setCacheTags($tags));

    $commerceContext = new Context(new AnonymousUserSession(), $store, NULL, ['field_name' => 'price']);
    $price = $this->priceResolver->resolve($variation, 1, $commerceContext);
    if (!$price) {
      return NULL;
    }
    $price = $this->rounder->round($price);
    $url = $variation->toUrl('canonical', ['absolute' => TRUE])->toString(TRUE);
    $context->addCacheableDependency($url);
    return [
      '@type' => 'Offer',
      'price' => $price->getNumber(),
      'priceCurrency' => $price->getCurrencyCode(),
      'availability' => 'https://schema.org/' . ($this->inStock($variation) ? 'InStock' : 'OutOfStock'),
      'url' => $url->getGeneratedUrl(),
      'seller' => SchemaIds::ref($this->ids->siteIdentity($context)),
    ];
  }

  /**
   * Whether a variation is in stock.
   *
   * An active variation is, unless commerce_stock is installed and tracks it
   * with nothing left.
   */
  protected function inStock(ProductVariationInterface $variation): bool {
    if (!$this->stock) {
      return TRUE;
    }
    $service = $this->stock->getService($variation);
    if ($service->getStockChecker()->getIsAlwaysInStock($variation)) {
      return TRUE;
    }
    return $this->stock->getStockLevel($variation) > 0;
  }

  /**
   * The product's brand, from its type's brand field.
   *
   * @return array{"@type": string, name: string}|null
   *   The Brand, or NULL when no field is mapped or it is empty.
   */
  public function brand(ProductInterface $product, PageContext $context): ?array {
    $productType = $this->entityTypeManager->getStorage('commerce_product_type')->load($product->bundle());
    if (!$productType instanceof ThirdPartySettingsInterface) {
      return NULL;
    }
    $context->addCacheableDependency($productType);
    $field = (string) $productType->getThirdPartySetting('neo_seo', 'brand_field', '');
    if ($field === '' || !$product->hasField($field) || $product->get($field)->isEmpty()) {
      return NULL;
    }
    $items = $product->get($field);
    if (!ProductTypeHooks::canNameBrand($items->getFieldDefinition())) {
      return NULL;
    }
    $item = $items->first();
    $target = $item->entity ?? NULL;
    if ($target instanceof TermInterface) {
      $context->addCacheableDependency($target);
      $name = $target->isPublished() ? (string) $target->label() : '';
    }
    else {
      $value = (string) $item->value;
      $allowed = $items->getFieldDefinition()->getSetting('allowed_values') ?? [];
      $name = (string) ($allowed[$value] ?? $value);
    }
    $name = trim($name);
    return $name !== '' ? ['@type' => 'Brand', 'name' => $name] : NULL;
  }

  /**
   * A variation's own image: its first image or media field with a file.
   *
   * @return string|null
   *   The image URL, or NULL.
   */
  public function variationImage(ProductVariationInterface $variation, PageContext $context): ?string {
    foreach ($variation->getFieldDefinitions() as $name => $definition) {
      $isImage = $definition->getType() === 'image';
      $isMedia = $definition->getType() === 'entity_reference' && $definition->getSetting('target_type') === 'media';
      if ((!$isImage && !$isMedia) || $variation->get($name)->isEmpty()) {
        continue;
      }
      if ($url = $this->imageUrl($variation, $name, $context)) {
        return $url;
      }
    }
    return NULL;
  }

  /**
   * The URL of the first item of an image or media field that has a file.
   */
  protected function imageUrl(FieldableEntityInterface $entity, string $field, PageContext $context): ?string {
    foreach ($entity->get($field) as $item) {
      $target = $item->entity ?? NULL;
      if ($target instanceof MediaInterface) {
        if ($image = $this->mediaImage->resolve((int) $target->id(), $context, TRUE)) {
          return $image['url'];
        }
      }
      elseif ($target && method_exists($target, 'getFileUri')) {
        $context->addCacheableDependency($target);
        return $this->mediaImage->file($target->getFileUri(), isset($item->width) ? (int) $item->width : NULL, isset($item->height) ? (int) $item->height : NULL, $context)['url'];
      }
    }
    return NULL;
  }

}
