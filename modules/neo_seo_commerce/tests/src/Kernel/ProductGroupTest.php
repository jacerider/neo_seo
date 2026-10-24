<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_seo_commerce\Kernel;

use Drupal\commerce_product\Entity\ProductAttribute;
use Drupal\commerce_product\Entity\ProductAttributeValue;
use Drupal\commerce_product\Entity\ProductVariation;
use PHPUnit\Framework\Attributes\Group;

/**
 * A product with several variations: a ProductGroup of variant Products.
 */
#[Group('neo_seo')]
class ProductGroupTest extends NeoSeoCommerceKernelTestBase {

  /**
   * Attribute value ids, keyed by attribute id and value name.
   *
   * @var array<string, array<string, int>>
   */
  protected array $values = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Color by its machine name, size by its label alone, finish by neither.
    $this->attribute('color', 'Color', ['Red', 'Blue', 'Green']);
    $this->attribute('fit', 'Size', ['Small', 'Large']);
    $this->attribute('finish', 'Finish', ['Matte', 'Gloss']);
  }

  /**
   * Variants differ by color and size: those vary, finish only names them.
   */
  public function testProductGroup(): void {
    $product = $this->product([
      ['sku' => 'RED-S', 'price' => '20'] + $this->pick(color: 'Red', fit: 'Small', finish: 'Matte'),
      ['sku' => 'RED-L', 'price' => '25'] + $this->pick(color: 'Red', fit: 'Large', finish: 'Gloss'),
      ['sku' => 'BLUE-L', 'price' => '25'] + $this->pick(color: 'Blue', fit: 'Large', finish: 'Matte'),
      ['sku' => 'GREEN-L', 'status' => FALSE] + $this->pick(color: 'Green', fit: 'Large', finish: 'Matte'),
    ]);
    $canonical = $this->canonical($product);
    [$document, $cache] = $this->page($product);
    $this->assertGraphReferencesResolve($document);

    $group = $this->assertGraphNode($document, $canonical . '#product');
    $this->assertSame('ProductGroup', $group['@type']);
    $this->assertSame((string) $product->id(), $group['productGroupID']);
    $this->assertSame('Garden chair', $group['name']);
    $this->assertSame($canonical, $group['url']);
    $this->assertSame(['https://schema.org/color', 'https://schema.org/size'], $group['variesBy']);
    $this->assertArrayNotHasKey('offers', $group, 'Each variant has its own Offer.');
    $this->assertArrayNotHasKey('sku', $group);
    $this->assertSame(['@id' => $canonical . '#product'], $this->assertGraphNode($document, $canonical)['mainEntity']);

    $this->assertSame(['RED-S', 'RED-L', 'BLUE-L'], array_column($group['hasVariant'], 'sku'), 'Only active variations, in order.');
    $variations = array_values(array_filter($product->getVariations(), static fn($variation) => $variation->isPublished()));
    foreach ($group['hasVariant'] as $index => $variant) {
      $variation = ProductVariation::load($variations[$index]->id());
      $this->assertSame('Product', $variant['@type']);
      $this->assertSame($canonical . '#product-' . $variation->id(), $variant['@id']);
      $this->assertSame($variation->getTitle(), $variant['name']);
      $this->assertSame($canonical . '?v=' . $variation->id(), $variant['url']);
      $this->assertSame($variant['url'], $variant['offers']['url']);
      $this->assertArrayNotHasKey('finish', $variant, 'An attribute schema.org does not name is not a property.');
    }
    $this->assertSame(['Red', 'Small'], [$group['hasVariant'][0]['color'], $group['hasVariant'][0]['size']]);
    $this->assertSame(['Blue', 'Large'], [$group['hasVariant'][2]['color'], $group['hasVariant'][2]['size']]);
    $this->assertSame(['20.00', '25.00', '25.00'], array_column(array_column($group['hasVariant'], 'offers'), 'price'));

    $this->assertContains('config:commerce_product.commerce_product_attribute.fit', $cache['tags'], 'A renamed attribute changes the match.');
  }

  /**
   * An attribute every variant shares does not vary, but still describes.
   */
  public function testSharedAttributeDoesNotVary(): void {
    $product = $this->product([
      ['sku' => 'RED-S'] + $this->pick(color: 'Red', fit: 'Small', finish: 'Matte'),
      ['sku' => 'RED-L'] + $this->pick(color: 'Red', fit: 'Large', finish: 'Matte'),
    ]);
    [$document] = $this->page($product);
    $group = $this->assertGraphNode($document, $this->canonical($product) . '#product');
    $this->assertSame(['https://schema.org/size'], $group['variesBy']);
    $this->assertSame(['Red', 'Red'], array_column($group['hasVariant'], 'color'));
  }

  /**
   * Variants told apart only by attributes schema.org does not name.
   */
  public function testNoMatchingAttribute(): void {
    $product = $this->product([
      ['sku' => 'MATTE'] + $this->pick(color: 'Red', fit: 'Small', finish: 'Matte'),
      ['sku' => 'GLOSS'] + $this->pick(color: 'Red', fit: 'Small', finish: 'Gloss'),
    ]);
    [$document] = $this->page($product);
    $group = $this->assertGraphNode($document, $this->canonical($product) . '#product');
    $this->assertSame('ProductGroup', $group['@type']);
    $this->assertArrayNotHasKey('variesBy', $group, 'Nothing schema.org names varies.');
    $names = array_column($group['hasVariant'], 'name');
    $this->assertCount(2, array_unique($names), 'The variants are told apart by name.');
  }

  /**
   * Age and gender describe the variant's audience.
   */
  public function testAudience(): void {
    $this->attribute('gender', 'Gender', ['Female', 'Male']);
    $this->attribute('age_group', 'Age group', ['Adult', 'Teens']);
    $product = $this->product([
      ['sku' => 'F'] + $this->pick(color: 'Red', fit: 'Small', finish: 'Matte', gender: 'Female', age_group: 'Adult'),
      ['sku' => 'M'] + $this->pick(color: 'Red', fit: 'Small', finish: 'Matte', gender: 'Male', age_group: 'Teens'),
    ]);
    [$document] = $this->page($product);
    $group = $this->assertGraphNode($document, $this->canonical($product) . '#product');
    $this->assertSame(['https://schema.org/suggestedAge', 'https://schema.org/suggestedGender'], $group['variesBy']);
    $this->assertSame([
      '@type' => 'PeopleAudience',
      'suggestedAge' => ['@type' => 'QuantitativeValue', 'minValue' => 13, 'unitCode' => 'ANN'],
      'suggestedGender' => 'female',
    ], $group['hasVariant'][0]['audience']);
    $this->assertSame(['@type' => 'QuantitativeValue', 'name' => 'Teens'], $group['hasVariant'][1]['audience']['suggestedAge'], 'An age that is not one of Google’s groups keeps its name.');
  }

  /**
   * Creates an attribute on the default variation type, with its values.
   *
   * @param string $id
   *   The attribute id.
   * @param string $label
   *   The attribute label.
   * @param string[] $names
   *   The value names.
   */
  protected function attribute(string $id, string $label, array $names): void {
    $attribute = ProductAttribute::create(['id' => $id, 'label' => $label]);
    $attribute->save();
    $this->container->get('commerce_product.attribute_field_manager')->createField($attribute, 'default');
    foreach ($names as $name) {
      $value = ProductAttributeValue::create(['attribute' => $id, 'name' => $name]);
      $value->save();
      $this->values[$id][$name] = (int) $value->id();
    }
  }

  /**
   * A variation's attribute field values, by attribute id and value name.
   *
   * @return array<string, int>
   *   Values keyed by attribute field name.
   */
  protected function pick(string ...$names): array {
    $fields = [];
    foreach ($names as $attribute => $name) {
      $fields['attribute_' . $attribute] = $this->values[$attribute][$name];
    }
    return $fields;
  }

}
