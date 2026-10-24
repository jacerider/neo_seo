<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_seo_commerce\Kernel;

use Drupal\commerce_product\Entity\ProductType;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * A product with one variation: a Product with an Offer.
 */
#[Group('neo_seo')]
class ProductTest extends NeoSeoCommerceKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['neo_seo_commerce_test'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installConfig(['image']);
  }

  /**
   * One active variation: a Product, its Offer, and the page about it.
   */
  public function testProductWithOffer(): void {
    $product = $this->product([['sku' => 'CHAIR-1', 'price' => '19.9']]);
    $variation = $product->getVariations()[0];
    $canonical = $this->canonical($product);
    [$document, $cache] = $this->page($product);
    $this->assertGraphReferencesResolve($document);

    $node = $this->assertGraphNode($document, $canonical . '#product');
    $this->assertSame('Product', $node['@type']);
    $this->assertSame('Garden chair', $node['name']);
    $this->assertSame('CHAIR-1', $node['sku']);
    $this->assertSame($canonical, $node['url']);
    $this->assertSame(['@id' => $canonical], $node['mainEntityOfPage']);
    $this->assertArrayNotHasKey('brand', $node, 'No brand field mapped, no brand.');
    $this->assertArrayNotHasKey('image', $node, 'Neither the product nor the variation has an image.');
    $this->assertSame([
      '@type' => 'Offer',
      'price' => '19.90',
      'priceCurrency' => 'USD',
      'availability' => 'https://schema.org/InStock',
      'url' => $canonical . '?v=' . $variation->id(),
      'seller' => ['@id' => 'http://localhost/#organization'],
    ], $node['offers']);

    $page = $this->assertGraphNode($document, $canonical);
    $this->assertSame(['WebPage', 'ItemPage'], $page['@type']);
    $this->assertSame(['@id' => $canonical . '#product'], $page['mainEntity']);

    $tags = [
      'commerce_product:' . $product->id(),
      'commerce_product_variation:' . $variation->id(),
      'commerce_store:' . $this->store->id(),
      'config:commerce_product.commerce_product_type.default',
    ];
    foreach ($tags as $tag) {
      $this->assertContains($tag, $cache['tags'], "The graph depends on $tag.");
    }
  }

  /**
   * The price is what an anonymous visitor pays: sales yes, members' no.
   */
  public function testPriceIsWhatVisitorsPay(): void {
    $this->setUpCurrentUser([], ['administer commerce_product']);
    $product = $this->product([['sku' => 'SALE-1', 'price' => '50']]);
    [$document] = $this->page($product);
    $node = $this->assertGraphNode($document, $this->canonical($product) . '#product');
    $this->assertSame('40.00', $node['offers']['price'], 'A fifth off, as the page shows a visitor; not the 35.00 a signed-in customer pays.');
  }

  /**
   * Only published variations are offered; with none, there is no Product.
   */
  public function testInactiveVariations(): void {
    $product = $this->product([
      ['sku' => 'OLD', 'status' => FALSE],
      ['sku' => 'NEW'],
    ]);
    [$document] = $this->page($product);
    $node = $this->assertGraphNode($document, $this->canonical($product) . '#product');
    $this->assertSame('Product', $node['@type'], 'One active variation is a Product, not a group.');
    $this->assertSame('NEW', $node['sku']);

    $retired = $this->product([['sku' => 'GONE', 'status' => FALSE]], ['title' => 'Retired chair']);
    [$document] = $this->page($retired);
    $this->assertNotContains($this->canonical($retired) . '#product', $this->graphIds($document));
    $page = $this->assertGraphNode($document, $this->canonical($retired));
    $this->assertArrayNotHasKey('mainEntity', $page);
  }

  /**
   * The brand comes from the product type's brand field: a term or text.
   */
  public function testBrand(): void {
    Vocabulary::create(['vid' => 'brands', 'name' => 'Brands'])->save();
    $brand = Term::create(['vid' => 'brands', 'name' => 'Northwind']);
    $brand->save();
    $this->field('commerce_product', 'field_brand', 'entity_reference', ['target_type' => 'taxonomy_term']);
    $this->field('commerce_product', 'field_maker', 'string');

    $productType = ProductType::load('default');
    $productType->setThirdPartySetting('neo_seo', 'brand_field', 'field_brand')->save();
    $product = $this->product([[]], ['field_brand' => $brand->id(), 'field_maker' => 'Fabrikam']);
    [$document, $cache] = $this->page($product);
    $node = $this->assertGraphNode($document, $this->canonical($product) . '#product');
    $this->assertSame(['@type' => 'Brand', 'name' => 'Northwind'], $node['brand']);
    $this->assertContains('taxonomy_term:' . $brand->id(), $cache['tags']);

    $productType->setThirdPartySetting('neo_seo', 'brand_field', 'field_maker')->save();
    [$document] = $this->page($product);
    $node = $this->assertGraphNode($document, $this->canonical($product) . '#product');
    $this->assertSame(['@type' => 'Brand', 'name' => 'Fabrikam'], $node['brand']);

    $plain = $this->product([[]], ['title' => 'Plain chair']);
    [$document] = $this->page($plain);
    $this->assertArrayNotHasKey('brand', $this->assertGraphNode($document, $this->canonical($plain) . '#product'), 'An empty brand field leaves the brand out.');
  }

  /**
   * The product's own image is the primary image; else the variation's.
   */
  public function testImage(): void {
    $this->field('commerce_product_variation', 'field_photo', 'image');
    $this->field('commerce_product', 'field_picture', 'image');
    $variationPhoto = $this->file('chair-red');
    $product = $this->product([['field_photo' => ['target_id' => $variationPhoto->id(), 'alt' => 'Red']]]);
    [$document] = $this->page($product);
    $node = $this->assertGraphNode($document, $this->canonical($product) . '#product');
    $this->assertStringContainsString('chair-red.png', $node['image'], 'Without an image of its own, the variation pictures the product.');

    ProductType::load('default')->setThirdPartySetting('neo_seo', 'image_field', 'field_picture')->save();
    $product->set('field_picture', ['target_id' => $this->file('chair')->id(), 'alt' => 'Chair'])->save();
    $canonical = $this->canonical($product);
    [$document] = $this->page($product);
    $this->assertGraphReferencesResolve($document);
    $node = $this->assertGraphNode($document, $canonical . '#product');
    $this->assertSame(['@id' => $canonical . '#primaryimage'], $node['image'], 'The mapped image is the page’s primary image, and the product’s.');
    $this->assertStringContainsString('chair.png', $this->assertGraphNode($document, $canonical . '#primaryimage')['contentUrl']);
  }

  /**
   * Adds a field to the default bundle of an entity type.
   */
  protected function field(string $entityTypeId, string $name, string $type, array $settings = []): void {
    FieldStorageConfig::create([
      'field_name' => $name,
      'entity_type' => $entityTypeId,
      'type' => $type,
      'settings' => $settings,
    ])->save();
    FieldConfig::create(['field_name' => $name, 'entity_type' => $entityTypeId, 'bundle' => 'default'])->save();
  }

  /**
   * Creates an image file.
   */
  protected function file(string $name): File {
    imagepng(imagecreatetruecolor(40, 20), "public://$name.png");
    $file = File::create(['uri' => "public://$name.png"]);
    $file->save();
    return $file;
  }

}
