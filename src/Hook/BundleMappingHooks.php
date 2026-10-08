<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Hook;

use Drupal\Core\Config\Entity\ThirdPartySettingsInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\BundleEntityFormBase;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\neo_seo\Mapping\BundleMapping;
use Drupal\neo_seo\Schema\SchemaTypes;

/**
 * The bundle mapping: a section on every routable bundle's edit form.
 */
class BundleMappingHooks {

  use StringTranslationTrait;

  /**
   * Field types that can supply a primary image.
   */
  protected const IMAGE_FIELD_TYPES = ['image', 'entity_reference'];

  /**
   * Field types that can supply a date.
   */
  protected const DATE_FIELD_TYPES = ['created', 'changed', 'timestamp', 'datetime'];

  /**
   * Constructs the hooks.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The entity field manager.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The current user.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typedConfig
   *   The typed config manager, to see which bundle types have a schema.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected AccountInterface $currentUser,
    protected TypedConfigManagerInterface $typedConfig,
  ) {}

  /**
   * Implements hook_form_alter().
   */
  #[Hook('form_alter')]
  public function formAlter(array &$form, FormStateInterface $form_state, string $form_id): void {
    $formObject = $form_state->getFormObject();
    if (!$formObject instanceof BundleEntityFormBase || !$this->currentUser->hasPermission('administer neo_seo')) {
      return;
    }
    $bundleEntity = $formObject->getEntity();
    $entityTypeId = $bundleEntity->getEntityType()->getBundleOf();
    $entityType = $entityTypeId ? $this->entityTypeManager->getDefinition($entityTypeId, FALSE) : NULL;
    if (!$bundleEntity instanceof ThirdPartySettingsInterface || !$entityType?->hasLinkTemplate('canonical')) {
      return;
    }
    // Third-party settings need a schema per bundle config type; neo_seo
    // declares node types, vocabularies and media types. Any other bundle type
    // gets the section once its schema declares
    // `{config prefix}.*.third_party.neo_seo: {type: neo_seo.bundle_mapping}`.
    $prefix = $bundleEntity->getEntityType()->getConfigPrefix();
    if (!$this->typedConfig->hasConfigSchema($prefix . '.' . $bundleEntity->id() . '.third_party.neo_seo')) {
      return;
    }

    $mapping = BundleMapping::fromSettings($bundleEntity->getThirdPartySettings('neo_seo'));
    $fields = $bundleEntity->isNew() ? [] : $this->entityFieldManager->getFieldDefinitions($entityTypeId, (string) $bundleEntity->id());
    $fieldOptions = function (array $types) use ($fields): array {
      $options = [];
      foreach ($fields as $name => $definition) {
        if (in_array($definition->getType(), $types, TRUE)) {
          $options[$name] = $definition->getLabel() . ' (' . $name . ')';
        }
      }
      return $options;
    };

    $form['neo_seo'] = [
      '#type' => 'details',
      '#title' => $this->t('SEO'),
      '#group' => isset($form['additional_settings']) ? 'additional_settings' : NULL,
      '#tree' => TRUE,
      '#weight' => 50,
    ];
    $form['neo_seo']['page_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Page type'),
      '#options' => SchemaTypes::pageTypes(),
      '#default_value' => $mapping->pageType,
    ];
    $form['neo_seo']['page_types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Page types editors may choose instead'),
      '#options' => SchemaTypes::pageTypes(),
      '#default_value' => $mapping->pageTypes,
    ];
    $form['neo_seo']['article_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Article type'),
      '#options' => SchemaTypes::articleTypes(),
      '#default_value' => $mapping->articleType,
      '#description' => $this->t('News, posts and reports are articles; most other pages are not.'),
    ];
    $form['neo_seo']['article_types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Article types editors may choose instead'),
      '#options' => SchemaTypes::articleTypes(),
      '#default_value' => $mapping->articleTypes,
    ];
    $form['neo_seo']['image_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Primary image'),
      '#options' => $fieldOptions(static::IMAGE_FIELD_TYPES),
      '#empty_option' => $this->t('- Share image template -'),
      '#default_value' => $mapping->imageField,
      '#access' => !$bundleEntity->isNew(),
    ];
    $form['neo_seo']['published_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Date published'),
      '#options' => $fieldOptions(static::DATE_FIELD_TYPES),
      '#empty_option' => $this->t('- Created -'),
      '#default_value' => $mapping->publishedField,
      '#access' => !$bundleEntity->isNew(),
    ];
    $form['neo_seo']['modified_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Date modified'),
      '#options' => $fieldOptions(static::DATE_FIELD_TYPES),
      '#empty_option' => $this->t('- Changed -'),
      '#default_value' => $mapping->modifiedField,
      '#access' => !$bundleEntity->isNew(),
    ];
    $form['neo_seo']['author_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Authors'),
      '#options' => $fieldOptions(['entity_reference']),
      '#empty_option' => $this->t('- None: the publisher only -'),
      '#default_value' => $mapping->authorField,
      '#description' => $this->t('A reference to content whose own type is a profile page. Each becomes an author of this page’s article.'),
      '#access' => !$bundleEntity->isNew(),
    ];
    $profileState = ['visible' => [':input[name="neo_seo[page_type]"]' => ['value' => 'ProfilePage']]];
    $form['neo_seo']['job_title_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Job title'),
      '#options' => $fieldOptions(['string', 'text', 'list_string']),
      '#empty_option' => $this->t('- None -'),
      '#default_value' => $mapping->jobTitleField,
      '#states' => $profileState,
      '#access' => !$bundleEntity->isNew(),
    ];
    $form['neo_seo']['same_as_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Social profiles'),
      '#options' => $fieldOptions(['link']),
      '#empty_option' => $this->t('- None -'),
      '#default_value' => $mapping->sameAsField,
      '#states' => $profileState,
      '#access' => !$bundleEntity->isNew(),
    ];
    $form['#entity_builders'][] = [static::class, 'buildBundleEntity'];
  }

  /**
   * Entity builder: stores the mapping as third-party settings.
   */
  public static function buildBundleEntity(string $entityTypeId, ThirdPartySettingsInterface $bundleEntity, array &$form, FormStateInterface $form_state): void {
    $values = (array) $form_state->getValue('neo_seo');
    foreach (BundleMapping::DEFAULTS as $key => $default) {
      if (!array_key_exists($key, $values)) {
        continue;
      }
      $value = is_array($default) ? array_values(array_filter((array) $values[$key])) : (string) $values[$key];
      if ($value === $default) {
        $bundleEntity->unsetThirdPartySetting('neo_seo', $key);
      }
      else {
        $bundleEntity->setThirdPartySetting('neo_seo', $key, $value);
      }
    }
  }

}
