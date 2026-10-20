<?php

declare(strict_types=1);

namespace Drupal\neo_seo_analysis\Controller;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\neo_seo_analysis\ImageMedia;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The media items behind the images the alt text check flags.
 */
class ImagesController implements ContainerInjectionInterface {

  use AutowireTrait;

  /**
   * The most image URLs looked up in one request.
   */
  public const LIMIT = 100;

  /**
   * Constructs the controller.
   *
   * @param \Drupal\neo_seo_analysis\ImageMedia $imageMedia
   *   Finds an image's media item.
   */
  public function __construct(
    protected ImageMedia $imageMedia,
  ) {}

  /**
   * Looks the images up, as JSON: {images: {url: {media, edit}|null}}.
   *
   * Query: src[], the image URLs as the page has them.
   */
  public function lookup(Request $request): JsonResponse {
    $srcs = $request->query->all()['src'] ?? [];
    $srcs = array_slice(array_values(array_filter(
      is_array($srcs) ? $srcs : [$srcs],
      static fn($src) => is_string($src) && $src !== '',
    )), 0, static::LIMIT);
    return new JsonResponse(['images' => (object) $this->imageMedia->resolve($srcs)]);
  }

}
