<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_seo_commerce\Kernel;

use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\commerce_store\StoreCreationTrait;
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

}
