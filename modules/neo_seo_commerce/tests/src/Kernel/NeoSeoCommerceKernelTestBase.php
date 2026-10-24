<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_seo_commerce\Kernel;

use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\commerce_store\StoreCreationTrait;
use Drupal\neo_seo\Hook\NeoSeoHooks;
use Drupal\Tests\neo_seo\Kernel\NeoSeoKernelTestBase;

/**
 * Base for neo_seo_commerce kernel tests: Commerce, a store, the module.
 *
 * The module is installed, not only enabled, so its install step has run:
 * products have SEO overrides and the default product type is an item page.
 */
abstract class NeoSeoCommerceKernelTestBase extends NeoSeoKernelTestBase {

  use StoreCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'address',
    'datetime',
    'entity',
    'options',
    'inline_entity_form',
    'views',
    'path',
    'path_alias',
    'text',
    'filter',
    'taxonomy',
    'commerce',
    'commerce_price',
    'commerce_store',
    'commerce_product',
  ];

  /**
   * The default store.
   */
  protected StoreInterface $store;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('user', ['users_data']);
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('commerce_currency');
    $this->installEntitySchema('commerce_store');
    $this->installEntitySchema('commerce_product');
    $this->installEntitySchema('commerce_product_variation');
    $this->installEntitySchema('commerce_product_attribute');
    $this->installEntitySchema('commerce_product_attribute_value');
    $this->installConfig(['filter', 'commerce_store', 'commerce_product']);
    $this->store = $this->createStore('Shop', 'shop@example.com');

    $this->container->get('module_installer')->install(['neo_seo_commerce'], FALSE);
    $this->container = \Drupal::getContainer();
  }

  /**
   * Creates a product with variations.
   *
   * @param array<int, array<string, mixed>> $variations
   *   Each variation's values; a `price` is a number in USD.
   * @param array<string, mixed> $values
   *   The product's values.
   */
  protected function product(array $variations, array $values = []): ProductInterface {
    $entities = [];
    foreach ($variations as $index => $variation) {
      $variation += ['type' => 'default', 'sku' => 'SKU-' . $index, 'price' => '10', 'status' => TRUE];
      $variation['price'] = new Price((string) $variation['price'], 'USD');
      $entity = ProductVariation::create($variation);
      $entity->save();
      $entities[] = $entity;
    }
    $product = Product::create($values + [
      'type' => 'default',
      'title' => 'Garden chair',
      'stores' => [$this->store],
      'variations' => $entities,
    ]);
    $product->save();
    return $product;
  }

  /**
   * The graph and head cacheability of a product's page.
   *
   * @return array{0: array, 1: array}
   *   The JSON-LD document and the attachments' #cache.
   */
  protected function page(ProductInterface $product): array {
    $this->pushRoute('entity.commerce_product.canonical', ['commerce_product' => $product], '/product/' . $product->id());
    $attachments = ['#attached' => []];
    $this->container->get(NeoSeoHooks::class)->pageAttachments($attachments);
    $document = [];
    foreach ($attachments['#attached']['html_head'] ?? [] as [$element, $key]) {
      if ($key === 'neo_seo_graph') {
        $document = json_decode((string) $element['#value'], TRUE);
      }
    }
    return [$document, $attachments['#cache'] ?? []];
  }

  /**
   * The canonical URL of a product's page.
   */
  protected function canonical(ProductInterface $product): string {
    return $product->toUrl('canonical', ['absolute' => TRUE])->toString();
  }

}
