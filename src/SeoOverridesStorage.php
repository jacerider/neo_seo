<?php

declare(strict_types=1);

namespace Drupal\neo_seo;

use Drupal\Core\Entity\EntityDefinitionUpdateManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorage;
use Psr\Log\LoggerInterface;

/**
 * Installs and removes the SEO overrides field storage per entity type.
 *
 * The field is a base field, so turning an entity type on or off in settings
 * changes the entity schema; this keeps the schema in step with the setting,
 * whether it changed in the form or arrived by config import.
 */
class SeoOverridesStorage {

  /**
   * Constructs the storage manager.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The entity field manager.
   * @param \Drupal\Core\Entity\EntityDefinitionUpdateManagerInterface $updateManager
   *   The entity definition update manager.
   * @param \Psr\Log\LoggerInterface $logger
   *   The neo_seo logger.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected EntityDefinitionUpdateManagerInterface $updateManager,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Brings the installed field storage in line with the enabled entity types.
   *
   * @param string[] $enabled
   *   The entity types that should have the field.
   */
  public function sync(array $enabled): void {
    $this->entityFieldManager->clearCachedFieldDefinitions();
    foreach ($this->entityTypeManager->getDefinitions() as $entityTypeId => $entityType) {
      $installed = $this->updateManager->getFieldStorageDefinition(SeoOverrides::FIELD, $entityTypeId);
      $wanted = in_array($entityTypeId, $enabled, TRUE);
      if ($wanted && !$installed) {
        $this->updateManager->installFieldStorageDefinition(
          SeoOverrides::FIELD,
          $entityTypeId,
          'neo_seo',
          SeoOverrides::definition($entityType->isRevisionable()),
        );
      }
      elseif (!$wanted && $installed && $installed->getProvider() === 'neo_seo') {
        if ($this->hasData($entityTypeId)) {
          // Never drop stored overrides on the way out; the form refuses this,
          // so it can only arrive by config import.
          $this->logger->error('Per-page SEO was turned off for %type, which still has stored overrides. The field is kept.', ['%type' => $entityTypeId]);
          continue;
        }
        $this->updateManager->uninstallFieldStorageDefinition($installed);
      }
    }
    $this->entityFieldManager->clearCachedFieldDefinitions();
  }

  /**
   * Whether any entity of a type has overrides stored.
   */
  public function hasData(string $entityTypeId): bool {
    $definition = $this->updateManager->getFieldStorageDefinition(SeoOverrides::FIELD, $entityTypeId);
    $storage = $this->entityTypeManager->getStorage($entityTypeId);
    return $definition && $storage instanceof SqlContentEntityStorage && $storage->countFieldData($definition, TRUE);
  }

}
