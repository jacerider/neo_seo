<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_seo_commerce\Kernel;

use Drupal\commerce_product\Entity\ProductType;
use Drupal\Core\Form\FormState;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\neo_seo\Mapping\BundleMappingRepository;
use Drupal\neo_seo\SeoOverrides;
use Drupal\neo_seo_commerce\Hook\ProductTypeHooks;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * Product types: SEO overrides, an item page by default, a brand field.
 */
#[Group('neo_seo')]
class ProductTypeTest extends NeoSeoCommerceKernelTestBase {

  use UserCreationTrait;

  /**
   * Installing gives products the overrides field and makes them item pages.
   */
  public function testInstall(): void {
    $this->assertContains('commerce_product', $this->config('neo_seo.settings')->get('override_entity_types'));
    $installed = $this->container->get('entity.definition_update_manager')->getFieldStorageDefinition(SeoOverrides::FIELD, 'commerce_product');
    $this->assertNotNull($installed, 'Products have the SEO overrides field.');

    $this->assertSame('ItemPage', ProductType::load('default')->getThirdPartySetting('neo_seo', 'page_type'), 'A product type that was there is an item page.');
    $repository = $this->container->get(BundleMappingRepository::class);
    $this->assertSame('ItemPage', $repository->forBundle('commerce_product', 'default')->pageType);

    $shoes = ProductType::create(['id' => 'shoes', 'label' => 'Shoes', 'variationTypes' => ['default']]);
    $shoes->save();
    $this->assertSame('ItemPage', $repository->forBundle('commerce_product', 'shoes')->pageType, 'So is a product type made later.');

    $this->container->get('config.typed')->clearCachedDefinitions();
    $this->assertTrue($this->container->get('config.typed')->hasConfigSchema('commerce_product.commerce_product_type.shoes.third_party.neo_seo'));
  }

  /**
   * Uninstalling takes the product types' mapping, whose schema it held.
   */
  public function testUninstall(): void {
    $this->container->get('module_installer')->uninstall(['neo_seo_commerce']);
    $this->assertSame([], ProductType::load('default')->getThirdPartySettings('neo_seo'));
    $this->assertContains('commerce_product', $this->config('neo_seo.settings')->get('override_entity_types'), 'Products keep their overrides until the site turns them off.');
  }

  /**
   * The product type form has the SEO and URL sections, and a brand field.
   */
  public function testProductTypeForm(): void {
    $this->brandFields();
    $this->setUpCurrentUser([], ['administer commerce_product_type', 'administer neo_seo']);
    $productType = ProductType::load('default');
    $form = $this->container->get('entity.form_builder')->getForm($productType, 'edit');
    $this->assertArrayHasKey('neo_seo', $form);
    $this->assertArrayHasKey('neo_seo_url', $form, 'Products have a path, so the type gets a URL section.');
    $this->assertSame('ItemPage', $form['neo_seo']['page_type']['#default_value']);
    $this->assertSame(['', 'field_brand', 'field_maker'], array_keys($form['neo_seo']['brand_field']['#options']), 'Term references and text fields the site added; not other references or base fields.');

    $formState = (new FormState())->setValue('neo_seo', ['page_type' => 'ItemPage', 'brand_field' => 'field_brand']);
    ProductTypeHooks::buildProductType('commerce_product_type', $productType, $form, $formState);
    $productType->save();
    $this->assertSame('field_brand', ProductType::load('default')->getThirdPartySetting('neo_seo', 'brand_field'));

    $formState->setValue('neo_seo', ['brand_field' => '']);
    ProductTypeHooks::buildProductType('commerce_product_type', $productType, $form, $formState);
    $productType->save();
    $this->assertNull(ProductType::load('default')->getThirdPartySetting('neo_seo', 'brand_field'), 'No brand stores nothing.');
  }

  /**
   * Without the SEO permission, the product type form has no SEO section.
   */
  public function testProductTypeFormNeedsPermission(): void {
    $this->setUpCurrentUser([], ['administer commerce_product_type']);
    $form = $this->container->get('entity.form_builder')->getForm(ProductType::load('default'), 'edit');
    $this->assertArrayNotHasKey('neo_seo', $form);
  }

  /**
   * Adds candidate brand fields to the default product type.
   */
  protected function brandFields(): void {
    $fields = [
      'field_brand' => ['entity_reference', ['target_type' => 'taxonomy_term']],
      'field_maker' => ['string', []],
      'field_related' => ['entity_reference', ['target_type' => 'commerce_product']],
    ];
    foreach ($fields as $name => [$type, $settings]) {
      FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => 'commerce_product',
        'type' => $type,
        'settings' => $settings,
      ])->save();
      FieldConfig::create([
        'field_name' => $name,
        'entity_type' => 'commerce_product',
        'bundle' => 'default',
        'label' => ucfirst(substr($name, 6)),
      ])->save();
    }
  }

}
