<?php

declare(strict_types=1);

namespace Drupal\neo_seo;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_seo\Mapping\BundleMapping;
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
   * Overrides from outside — a browser, a draft — reduced to what may be kept.
   *
   * Only the field's own properties survive, each as its stored type. A key
   * whose value cannot be stored is dropped rather than cleared, so merging
   * the result onto saved overrides keeps the saved value: a page type the
   * bundle does not allow, a score outside 0–100. An empty string clears.
   * The share image is checked for shape only; whether that media exists is
   * the caller's to check.
   *
   * @param array $values
   *   The values, keyed by property name.
   * @param \Drupal\neo_seo\Mapping\BundleMapping $mapping
   *   The bundle's mapping, for the page and article types it allows.
   *
   * @return array<string, mixed>
   *   The values that may be stored.
   */
  public static function clean(array $values, BundleMapping $mapping): array {
    $clean = [];
    foreach (['title' => 255, 'description' => 0, 'keyphrase' => 255] as $key => $length) {
      if (array_key_exists($key, $values) && (is_string($values[$key]) || $values[$key] === NULL)) {
        $value = trim((string) $values[$key]);
        $clean[$key] = $value === '' ? NULL : ($length ? mb_substr($value, 0, $length) : $value);
      }
    }
    if (array_key_exists('image', $values)) {
      $image = $values['image'];
      if ($image === NULL || $image === '' || $image === 0 || $image === '0') {
        $clean['image'] = NULL;
      }
      elseif ((is_int($image) || (is_string($image) && ctype_digit($image))) && (int) $image > 0) {
        $clean['image'] = (int) $image;
      }
    }
    $types = [
      'page_type' => $mapping->allowedPageTypes(),
      'article_type' => $mapping->allowedArticleTypes(),
    ];
    foreach ($types as $key => $allowed) {
      if (!array_key_exists($key, $values)) {
        continue;
      }
      if ($values[$key] === NULL || $values[$key] === '') {
        $clean[$key] = NULL;
      }
      elseif (is_string($values[$key]) && in_array($values[$key], $allowed, TRUE)) {
        $clean[$key] = $values[$key];
      }
    }
    foreach (['noindex', 'cornerstone'] as $key) {
      if (array_key_exists($key, $values) && is_scalar($values[$key] ?? FALSE)) {
        $clean[$key] = (bool) $values[$key];
      }
    }
    foreach (SeoOverridesItem::SCORES as $key) {
      if (!array_key_exists($key, $values)) {
        continue;
      }
      $score = static::score($values[$key]);
      if ($score !== FALSE) {
        $clean[$key] = $score;
      }
    }
    return $clean;
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
