<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Mapping;

use Drupal\Core\Config\Entity\ThirdPartySettingsInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\media\MediaInterface;
use Drupal\neo_seo\Graph\PageContext;
use Drupal\neo_seo\MediaImage;
use Drupal\neo_seo\SeoOverrides;

/**
 * Reads a bundle's mapping, and the values it points an entity at.
 */
class BundleMappingRepository {

  /**
   * Constructs the repository.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\neo_seo\MediaImage $mediaImage
   *   The media image helper.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected MediaImage $mediaImage,
  ) {}

  /**
   * The bundle config entity that stores a mapping, if the type has bundles.
   */
  public function bundleEntity(string $entityTypeId, string $bundle): ?ThirdPartySettingsInterface {
    $bundleEntityType = $this->entityTypeManager->getDefinition($entityTypeId, FALSE)?->getBundleEntityType();
    if (!$bundleEntityType) {
      return NULL;
    }
    $bundleEntity = $this->entityTypeManager->getStorage($bundleEntityType)->load($bundle);
    return $bundleEntity instanceof ThirdPartySettingsInterface ? $bundleEntity : NULL;
  }

  /**
   * A bundle's mapping.
   */
  public function forBundle(string $entityTypeId, string $bundle): BundleMapping {
    $bundleEntity = $this->bundleEntity($entityTypeId, $bundle);
    return BundleMapping::fromSettings($bundleEntity ? $bundleEntity->getThirdPartySettings('neo_seo') : []);
  }

  /**
   * The mapping of a page's entity, recording the bundle on the page.
   */
  public function forPage(PageContext $context): BundleMapping {
    $entity = $context->entity;
    if (!$entity) {
      return new BundleMapping();
    }
    if ($bundleEntity = $this->bundleEntity($entity->getEntityTypeId(), $entity->bundle())) {
      $context->addCacheableDependency($bundleEntity);
    }
    return $this->forBundle($entity->getEntityTypeId(), $entity->bundle());
  }

  /**
   * The page's page type: an allowed override, else the bundle's.
   */
  public function pageType(PageContext $context): string {
    return $this->forPage($context)->resolvePageType(SeoOverrides::of($context->entity)['page_type']);
  }

  /**
   * The page's article type: an allowed override, else the bundle's.
   */
  public function articleType(PageContext $context): string {
    return $this->forPage($context)->resolveArticleType(SeoOverrides::of($context->entity)['article_type']);
  }

  /**
   * The image of the bundle's image field on a page's entity.
   *
   * A media reference or an image field; the first item that has a file.
   *
   * @return array{url: string, width: ?int, height: ?int, alt: ?string, caption: string}|null
   *   The image, or NULL.
   */
  public function image(PageContext $context): ?array {
    $field = $this->forPage($context)->imageField;
    $items = $this->items($context->entity, $field);
    foreach ($items ?? [] as $item) {
      $target = $item->entity ?? NULL;
      if ($target instanceof MediaInterface) {
        if ($image = $this->mediaImage->resolve((int) $target->id(), $context, TRUE)) {
          return $image;
        }
      }
      elseif ($target && method_exists($target, 'getFileUri')) {
        $context->addCacheableDependency($target);
        $alt = trim((string) ($item->alt ?? ''));
        return $this->mediaImage->file(
          $target->getFileUri(),
          isset($item->width) ? (int) $item->width : NULL,
          isset($item->height) ? (int) $item->height : NULL,
          $context,
        ) + [
          'alt' => $alt !== '' ? $alt : NULL,
          'caption' => $alt,
        ];
      }
    }
    return NULL;
  }

  /**
   * When the page's entity was published, as an ISO 8601 date.
   */
  public function datePublished(PageContext $context): ?string {
    $field = $this->forPage($context)->publishedField ?: 'created';
    return $this->date($this->items($context->entity, $field));
  }

  /**
   * When the page's entity was last modified, as an ISO 8601 date.
   */
  public function dateModified(PageContext $context): ?string {
    $field = $this->forPage($context)->modifiedField;
    if ($field === '' && $context->entity instanceof EntityChangedInterface) {
      return date('c', $context->entity->getChangedTime());
    }
    return $this->date($this->items($context->entity, $field ?: 'changed'));
  }

  /**
   * A field's items on an entity, or NULL when it has no such non-empty field.
   */
  protected function items(?FieldableEntityInterface $entity, string $field): ?FieldItemListInterface {
    if (!$entity || $field === '' || !$entity->hasField($field) || $entity->get($field)->isEmpty()) {
      return NULL;
    }
    return $entity->get($field);
  }

  /**
   * An ISO 8601 date from a timestamp or datetime field.
   */
  protected function date(?FieldItemListInterface $items): ?string {
    $item = $items?->first();
    if (!$item) {
      return NULL;
    }
    $value = $item->value;
    if (is_numeric($value)) {
      return date('c', (int) $value);
    }
    if ($item->getFieldDefinition()->getType() === 'datetime') {
      // A date-only value has no time of day; inventing one would publish a
      // midnight in some timezone that never happened, so it stays a date.
      if ($item->getFieldDefinition()->getSetting('datetime_type') === 'date') {
        return (string) $value;
      }
      return isset($item->date) ? $item->date->format('c') : NULL;
    }
    $timestamp = strtotime((string) $value);
    return $timestamp ? date('c', $timestamp) : NULL;
  }

}
