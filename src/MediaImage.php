<?php

declare(strict_types=1);

namespace Drupal\neo_seo;

use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\media\MediaInterface;
use Drupal\neo_seo\Graph\PageContext;

/**
 * Turns an image media item into what a head tag or graph node needs.
 */
class MediaImage {

  /**
   * Constructs the media image helper.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entityRepository
   *   The entity repository.
   * @param \Drupal\Core\File\FileUrlGeneratorInterface $fileUrlGenerator
   *   The file URL generator.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityRepositoryInterface $entityRepository,
    protected FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  /**
   * The image of a media item, by id or uuid.
   *
   * @param int|string|null $reference
   *   A media id, or a uuid.
   * @param \Drupal\neo_seo\Graph\PageContext $context
   *   The page; the media and file are recorded on it.
   *
   * @return array{url: string, width: ?int, height: ?int, alt: ?string, caption: string}|null
   *   The image, or NULL when the media is missing or has no file.
   */
  public function resolve(int|string|null $reference, PageContext $context): ?array {
    if ($reference === NULL || $reference === '' || $reference === 0) {
      return NULL;
    }
    $media = is_numeric($reference)
      ? $this->entityTypeManager->getStorage('media')->load($reference)
      : $this->entityRepository->loadEntityByUuid('media', (string) $reference);
    if (!$media instanceof MediaInterface) {
      return NULL;
    }
    $media = $this->entityRepository->getTranslationFromContext($media, $context->langcode);
    $context->addCacheableDependency($media);
    $sourceField = $media->getSource()->getConfiguration()['source_field'] ?? NULL;
    $item = $sourceField && $media->hasField($sourceField) ? $media->get($sourceField)->first() : NULL;
    $file = $item?->entity;
    if (!$file) {
      return NULL;
    }
    $context->addCacheableDependency($file);
    $alt = trim((string) ($item->alt ?? ''));
    return [
      'url' => $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri()),
      'width' => isset($item->width) ? (int) $item->width : NULL,
      'height' => isset($item->height) ? (int) $item->height : NULL,
      'alt' => $alt !== '' ? $alt : NULL,
      'caption' => $alt !== '' ? $alt : (string) $media->label(),
    ];
  }

}
