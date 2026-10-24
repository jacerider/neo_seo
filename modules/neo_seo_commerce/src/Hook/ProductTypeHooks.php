<?php

declare(strict_types=1);

namespace Drupal\neo_seo_commerce\Hook;

use Drupal\Core\Config\Entity\ThirdPartySettingsInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Product types: an item page by default, and a brand field in the mapping.
 *
 * The SEO and URL sections themselves are neo_seo's bundle form hook, which
 * shows them once the product type's mapping has a schema (this module's).
 */
class ProductTypeHooks {

  use StringTranslationTrait;

  /**
   * The page type a new product type starts with.
   */
  public const PAGE_TYPE = 'ItemPage';

  /**
   * Constructs the hooks.
   *
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The entity field manager.
   */
  public function __construct(
    protected EntityFieldManagerInterface $entityFieldManager,
  ) {}

  /**
   * Implements hook_ENTITY_TYPE_create() for commerce_product_type.
   *
   * A product page is an item page. Only a type made here starts as one: a
   * type arriving by config import or install carries its own mapping.
   */
  #[Hook('commerce_product_type_create')]
  public function productTypeCreate(EntityInterface $productType): void {
    if ($productType instanceof ThirdPartySettingsInterface && $productType->getThirdPartySetting('neo_seo', 'page_type') === NULL) {
      $productType->setThirdPartySetting('neo_seo', 'page_type', static::PAGE_TYPE);
    }
  }

  /**
   * Implements hook_form_BASE_FORM_ID_alter() for commerce_product_type_form.
   *
   * Adds the brand field to the SEO section neo_seo built, which is only
   * there for those who administer SEO.
   */
  #[Hook('form_commerce_product_type_form_alter')]
  public function productTypeFormAlter(array &$form, FormStateInterface $form_state): void {
    $productType = $form_state->getFormObject()->getEntity();
    if (!isset($form['neo_seo']) || !$productType instanceof ThirdPartySettingsInterface) {
      return;
    }
    $form['neo_seo']['brand_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Brand'),
      '#options' => $productType->isNew() ? [] : $this->brandFieldOptions((string) $productType->id()),
      '#empty_option' => $this->t('- None -'),
      '#default_value' => $productType->getThirdPartySetting('neo_seo', 'brand_field', ''),
      '#description' => $this->t('A taxonomy term reference or text field on the product. Its value is the product’s brand; without one, the brand is left out.'),
      '#access' => !$productType->isNew(),
      '#weight' => 10,
    ];
    $form['#entity_builders'][] = [static::class, 'buildProductType'];
  }

  /**
   * Entity builder: stores the brand field with the rest of the mapping.
   */
  public static function buildProductType(string $entityTypeId, ThirdPartySettingsInterface $productType, array &$form, FormStateInterface $form_state): void {
    $values = (array) $form_state->getValue('neo_seo');
    if (!array_key_exists('brand_field', $values)) {
      return;
    }
    $field = (string) $values['brand_field'];
    if ($field === '') {
      $productType->unsetThirdPartySetting('neo_seo', 'brand_field');
    }
    else {
      $productType->setThirdPartySetting('neo_seo', 'brand_field', $field);
    }
  }

  /**
   * The product type's fields that can name a brand.
   *
   * A taxonomy term reference or a text field the site added; base fields
   * (the title, the SKU) never hold a brand.
   *
   * @return array<string, string>
   *   Field labels keyed by field name.
   */
  protected function brandFieldOptions(string $bundle): array {
    $options = [];
    foreach ($this->entityFieldManager->getFieldDefinitions('commerce_product', $bundle) as $name => $definition) {
      if (static::canNameBrand($definition)) {
        $options[$name] = $definition->getLabel() . ' (' . $name . ')';
      }
    }
    return $options;
  }

  /**
   * Whether a field can name a brand.
   */
  public static function canNameBrand(FieldDefinitionInterface $definition): bool {
    if ($definition->getFieldStorageDefinition()->isBaseField()) {
      return FALSE;
    }
    return match ($definition->getType()) {
      'string', 'list_string' => TRUE,
      'entity_reference' => $definition->getSetting('target_type') === 'taxonomy_term',
      default => FALSE,
    };
  }

}
