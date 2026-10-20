<?php

declare(strict_types=1);

namespace Drupal\neo_seo_analysis;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\Core\Url;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The media item behind an image on a page, for fixing its alt text.
 *
 * Alt text lives on the media item, not where the image is shown: a card that
 * lists other pages shows their images without a field for the alt. An image
 * is found from its URL — the file itself, or an image style derivative of it
 * (`/styles/{style}/{scheme}/…`, a converting style's added extension taken
 * off) — then its file, then a media item whose source field holds it. The
 * media module is optional; without it nothing is found.
 */
class ImageMedia {

  /**
   * The query parameter marking a media edit form opened from the panel.
   */
  public const QUERY = 'neo_seo_media';

  /**
   * Constructs the lookup.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface $streamWrappers
   *   The stream wrapper manager, for where each scheme's files are served.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack, for the site's base path.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   Who would edit the media item.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected StreamWrapperManagerInterface $streamWrappers,
    protected RequestStack $requestStack,
    protected AccountInterface $currentUser,
  ) {}

  /**
   * The media items behind image URLs.
   *
   * @param string[] $srcs
   *   Image URLs, as the page has them.
   *
   * @return array<string, array{media: int, edit: string|null}|null>
   *   Keyed by URL: the media item and, where the user may edit it, its edit
   *   form's URL (opened from the panel); NULL when no media item holds it.
   */
  public function resolve(array $srcs): array {
    $found = [];
    foreach ($srcs as $src) {
      $media = $this->media((string) $src);
      if (!$media) {
        $found[$src] = NULL;
        continue;
      }
      $found[$src] = [
        'media' => (int) $media->id(),
        'edit' => $media->access('update', $this->currentUser)
          ? $media->toUrl('edit-form', ['query' => [static::QUERY => 1]])->toString(TRUE)->getGeneratedUrl()
          : NULL,
      ];
    }
    return $found;
  }

  /**
   * The media item holding the file an image URL shows.
   */
  public function media(string $src): ?MediaInterface {
    if (!$this->entityTypeManager->hasDefinition('media')) {
      return NULL;
    }
    $file = $this->file($src);
    if (!$file) {
      return NULL;
    }
    $storage = $this->entityTypeManager->getStorage('media');
    foreach ($this->entityTypeManager->getStorage('media_type')->loadMultiple() as $type) {
      $field = $type->getSource()->getConfiguration()['source_field'] ?? '';
      if ($field === '') {
        continue;
      }
      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('bundle', $type->id())
        ->condition($field . '.target_id', $file->id())
        ->sort('mid')
        ->range(0, 1)
        ->execute();
      if ($ids) {
        $media = $storage->load(reset($ids));
        return $media instanceof MediaInterface ? $media : NULL;
      }
    }
    return NULL;
  }

  /**
   * The file an image URL shows, itself or through a derivative.
   */
  public function file(string $src): ?FileInterface {
    if (!$this->entityTypeManager->hasDefinition('file')) {
      return NULL;
    }
    $files = $this->entityTypeManager->getStorage('file');
    foreach ($this->uris($src) as $uri) {
      $matches = $files->loadByProperties(['uri' => $uri]);
      $file = reset($matches);
      if ($file instanceof FileInterface) {
        return $file;
      }
    }
    return NULL;
  }

  /**
   * The file URIs an image URL may stand for, likeliest first.
   *
   * @return string[]
   *   URIs such as public://2026-07/photo.jpg.
   */
  public function uris(string $src): array {
    $path = rawurldecode((string) parse_url($src, PHP_URL_PATH));
    $base = rtrim((string) $this->requestStack->getCurrentRequest()?->getBasePath(), '/');
    if ($base !== '' && str_starts_with($path, $base . '/')) {
      $path = substr($path, strlen($base));
    }
    $path = ltrim($path, '/');
    // Where each local scheme's files are served: public under its directory,
    // private through the system/files route.
    $roots = ['private' => 'system/files'];
    $public = $this->streamWrappers->getViaScheme('public');
    if ($public && method_exists($public, 'getDirectoryPath')) {
      $roots['public'] = trim((string) $public->getDirectoryPath(), '/');
    }
    $uris = [];
    foreach ($roots as $scheme => $root) {
      if ($root === '' || !str_starts_with($path, $root . '/')) {
        continue;
      }
      $rest = substr($path, strlen($root) + 1);
      // A derivative: styles/{style}/{scheme}/{target}.
      if (preg_match('#^styles/[^/]+/([a-z0-9]+)/(.+)$#', $rest, $match)) {
        $target = $match[2];
        // A converting style appends its format: photo.jpg.avif.
        if (preg_match('#^(.+\.[A-Za-z0-9]{2,5})\.[A-Za-z0-9]{2,5}$#', $target, $converted)) {
          $uris[] = $match[1] . '://' . $converted[1];
        }
        $uris[] = $match[1] . '://' . $target;
        continue;
      }
      $uris[] = $scheme . '://' . $rest;
    }
    return array_values(array_unique($uris));
  }

  /**
   * Whether the current request is a media edit form opened from the panel.
   */
  public function fromPanel(): bool {
    return (bool) $this->requestStack->getCurrentRequest()?->query->get(static::QUERY);
  }

  /**
   * The lookup's URL, for the panel.
   */
  public static function url(): string {
    return Url::fromRoute('neo_seo_analysis.images')->toString(TRUE)->getGeneratedUrl();
  }

}
