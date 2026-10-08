<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Hook;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityLastInstalledSchemaRepositoryInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;
use Drupal\neo_seo\SeoOverrides;

/**
 * The SEO overrides base field: where it exists, and who may edit it.
 */
class SeoOverridesHooks {

  /**
   * Constructs the hooks.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory. Read directly, not through the settings repository:
   *   base field info is collected while the container may still be building.
   * @param \Drupal\Core\Entity\EntityLastInstalledSchemaRepositoryInterface $installedSchema
   *   The last installed schema repository.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected EntityLastInstalledSchemaRepositoryInterface $installedSchema,
  ) {}

  /**
   * Implements hook_entity_base_field_info().
   *
   * On the entity types turned on in settings — and on any that still has the
   * storage installed, because turning a type off while overrides are stored
   * keeps them, and a stored field must stay defined or the entity schema and
   * its definitions disagree.
   */
  #[Hook('entity_base_field_info')]
  public function entityBaseFieldInfo(EntityTypeInterface $entityType): array {
    $enabled = (array) $this->configFactory->get('neo_seo.settings')->get('override_entity_types');
    $installed = $this->installedSchema->getLastInstalledFieldStorageDefinitions($entityType->id());
    if (!in_array($entityType->id(), $enabled, TRUE) && !isset($installed[SeoOverrides::FIELD])) {
      return [];
    }
    return [SeoOverrides::FIELD => SeoOverrides::definition($entityType->isRevisionable())];
  }

  /**
   * Implements hook_entity_field_access().
   *
   * Seeing the overrides is anyone's who can see the entity; changing them
   * takes the permission, because they decide how the page appears in search.
   */
  #[Hook('entity_field_access')]
  public function entityFieldAccess(string $operation, FieldDefinitionInterface $field, AccountInterface $account, ?FieldItemListInterface $items = NULL): AccessResultInterface {
    if ($field->getName() !== SeoOverrides::FIELD || $operation !== 'edit') {
      return AccessResult::neutral();
    }
    return AccessResult::forbiddenIf(!$account->hasPermission('edit neo_seo overrides'))->cachePerPermissions();
  }

}
