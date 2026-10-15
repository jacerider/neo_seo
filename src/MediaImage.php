<?php

declare(strict_types=1);

namespace Drupal\neo_seo;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\image\ImageStyleInterface;
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
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory, for the share image style.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityRepositoryInterface $entityRepository,
    protected FileUrlGeneratorInterface $fileUrlGenerator,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * The image of a media item, by id or uuid.
   *
   * @param int|string|null $reference
   *   A media id, or a uuid.
   * @param \Drupal\neo_seo\Graph\PageContext $context
   *   The page; the media and file are recorded on it.
   * @param bool $share
   *   TRUE for an image meant for sharing: it is served through the share
   *   image style, when one is set, with that style's dimensions.
   *
   * @return array{url: string, width: ?int, height: ?int, alt: ?string, caption: string}|null
   *   The image, or NULL when the media is missing or has no file.
   */
  public function resolve(int|string|null $reference, PageContext $context, bool $share = FALSE): ?array {
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
    $image = $this->file(
      $file->getFileUri(),
      isset($item->width) ? (int) $item->width : NULL,
      isset($item->height) ? (int) $item->height : NULL,
      $share ? $context : NULL,
    );
    return $image + [
      'alt' => $alt !== '' ? $alt : NULL,
      'caption' => $alt !== '' ? $alt : (string) $media->label(),
    ];
  }

  /**
   * An image file's URL and dimensions, through the share style if asked.
   *
   * Never enlarged: when the style would make the image bigger than it is (a
   * small image cropped up to the card), the file is shared as it is. With
   * its dimensions unknown the style is used.
   *
   * @param string $uri
   *   The file URI.
   * @param int|null $width
   *   The original width.
   * @param int|null $height
   *   The original height.
   * @param \Drupal\neo_seo\Graph\PageContext|null $share
   *   The page, when the image is for sharing.
   *
   * @return array{url: string, width: ?int, height: ?int}
   *   The URL and dimensions.
   */
  public function file(string $uri, ?int $width, ?int $height, ?PageContext $share = NULL): array {
    $style = $share ? $this->shareStyle($share) : NULL;
    if ($style && $style->supportsUri($uri)) {
      $dimensions = ['width' => $width, 'height' => $height];
      $style->transformDimensions($dimensions, $uri);
      $enlarged = $width !== NULL && $height !== NULL
        && ((int) $dimensions['width'] > $width || (int) $dimensions['height'] > $height);
      if ($enlarged) {
        return [
          'url' => $this->fileUrlGenerator->generateAbsoluteString($uri),
          'width' => $width,
          'height' => $height,
        ];
      }
      return [
        'url' => $style->buildUrl($uri),
        'width' => $dimensions['width'] !== NULL ? (int) $dimensions['width'] : NULL,
        'height' => $dimensions['height'] !== NULL ? (int) $dimensions['height'] : NULL,
      ];
    }
    return [
      'url' => $this->fileUrlGenerator->generateAbsoluteString($uri),
      'width' => $width,
      'height' => $height,
    ];
  }

  /**
   * The image style share images are served through, if set and present.
   */
  protected function shareStyle(PageContext $context): ?ImageStyleInterface {
    $settings = $this->configFactory->get('neo_seo.settings');
    $context->addCacheableDependency($settings);
    $id = (string) $settings->get('share_image_style');
    if ($id === '' || !$this->entityTypeManager->hasDefinition('image_style')) {
      return NULL;
    }
    $style = $this->entityTypeManager->getStorage('image_style')->load($id);
    if ($style instanceof ImageStyleInterface) {
      $context->addCacheableDependency($style);
      return $style;
    }
    return NULL;
  }

}
