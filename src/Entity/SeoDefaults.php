<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Entity\EntityDeleteForm;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_seo\Form\SeoDefaultsForm;
use Drupal\neo_seo\SeoDefaultsListBuilder;

/**
 * A layer of default head-tag templates.
 *
 * The id says where the layer applies: `global`, an entity type (`node`), a
 * bundle (`node__insight`), or one of `front`, `403`, `404`.
 */
#[ConfigEntityType(
  id: 'neo_seo_defaults',
  label: new TranslatableMarkup('SEO defaults'),
  label_collection: new TranslatableMarkup('Default templates'),
  label_singular: new TranslatableMarkup('default template set'),
  label_plural: new TranslatableMarkup('default template sets'),
  config_prefix: 'defaults',
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
    'status' => 'status',
  ],
  handlers: [
    'list_builder' => SeoDefaultsListBuilder::class,
    'form' => [
      'add' => SeoDefaultsForm::class,
      'edit' => SeoDefaultsForm::class,
      'delete' => EntityDeleteForm::class,
    ],
    'route_provider' => ['html' => AdminHtmlRouteProvider::class],
  ],
  links: [
    'collection' => '/admin/config/neo/seo/defaults',
    'add-form' => '/admin/config/neo/seo/defaults/add',
    'edit-form' => '/admin/config/neo/seo/defaults/{neo_seo_defaults}',
    'delete-form' => '/admin/config/neo/seo/defaults/{neo_seo_defaults}/delete',
  ],
  admin_permission: 'administer neo_seo',
  label_count: [
    'singular' => '@count default template set',
    'plural' => '@count default template sets',
  ],
  config_export: [
    'id',
    'label',
    'values',
  ],
)]
class SeoDefaults extends ConfigEntityBase implements SeoDefaultsInterface {

  /**
   * The id: global, front, 403, 404, an entity type or entity_type__bundle.
   */
  protected ?string $id = NULL;

  /**
   * The label.
   */
  protected ?string $label = NULL;

  /**
   * The templates, keyed by field name.
   *
   * @var array<string, string>
   */
  protected array $values = [];

  /**
   * {@inheritdoc}
   */
  public function getValues(): array {
    return $this->values;
  }

  /**
   * {@inheritdoc}
   */
  public function setValues(array $values): self {
    $this->values = array_map('strval', $values);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function isDeletable(): bool {
    return $this->id() !== 'global';
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    parent::calculateDependencies();
    $id = (string) $this->id();
    if (in_array($id, static::SPECIAL, TRUE)) {
      return $this;
    }
    [$entityTypeId, $bundle] = array_pad(explode('__', $id, 2), 2, NULL);
    $entityType = $this->entityTypeManager()->getDefinition($entityTypeId, FALSE);
    if (!$entityType) {
      return $this;
    }
    $this->addDependency('module', $entityType->getProvider());
    if ($bundle && ($bundleEntityType = $entityType->getBundleEntityType())) {
      $bundleEntity = $this->entityTypeManager()->getStorage($bundleEntityType)->load($bundle);
      if ($bundleEntity) {
        $this->addDependency('config', $bundleEntity->getConfigDependencyName());
      }
    }
    return $this;
  }

}
