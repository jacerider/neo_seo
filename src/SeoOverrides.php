<?php

declare(strict_types=1);

namespace Drupal\neo_seo;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_seo\Plugin\Field\FieldType\SeoOverridesItem;

/**
 * The SEO overrides base field: its definition, and reading it.
 */
final class SeoOverrides {

  /**
   * The base field's name on every entity type it is added to.
   */
  public const FIELD = 'neo_seo';

  /**
   * The base field definition for an entity type.
   *
   * @param bool $revisionable
   *   Whether the entity type is revisionable.
   *
   * @return \Drupal\Core\Field\BaseFieldDefinition
   *   The definition.
   */
  public static function definition(bool $revisionable): BaseFieldDefinition {
    return BaseFieldDefinition::create('neo_seo_overrides')
      ->setLabel(new TranslatableMarkup('SEO'))
      ->setDescription(new TranslatableMarkup('Per-page search and sharing settings.'))
      ->setTranslatable(TRUE)
      ->setRevisionable($revisionable)
      ->setCardinality(1)
      ->setDisplayOptions('form', ['type' => 'neo_seo_overrides', 'weight' => 90])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', FALSE);
  }

  /**
   * A score as stored: an integer 0–100, NULL for none, FALSE if invalid.
   */
  public static function score(mixed $value): int|null|false {
    if ($value === NULL || $value === '') {
      return NULL;
    }
    if (is_int($value) || (is_string($value) && preg_match('/^\d{1,3}$/', $value))) {
      $value = (int) $value;
      return $value >= 0 && $value <= 100 ? $value : FALSE;
    }
    return FALSE;
  }

  /**
   * An entity's overrides, with nothing set as NULL.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface|null $entity
   *   The entity, which may not have the field.
   *
   * @return array<string, mixed>
   *   Every property of the field, NULL where unset.
   */
  public static function of(?FieldableEntityInterface $entity): array {
    $empty = array_fill_keys([...SeoOverridesItem::EDITABLE, 'seo_score', 'readability_score'], NULL);
    if (!$entity || !$entity->hasField(self::FIELD) || $entity->get(self::FIELD)->isEmpty()) {
      return $empty;
    }
    $values = $entity->get(self::FIELD)->first()->getValue();
    foreach ($values as $key => $value) {
      if ($value === '') {
        $values[$key] = NULL;
      }
    }
    return $values + $empty;
  }

}
