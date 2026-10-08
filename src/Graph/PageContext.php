<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Graph;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\ContentEntityInterface;

/**
 * The page a graph describes: what every graph piece and head tag reads.
 *
 * Built once per request by PageContextFactory, or by hand in a test. The
 * values are fixed at construction; the cacheability is the one thing that
 * grows, because a piece that reads something (a config object, a referenced
 * entity) records it here, and the whole set rides out on the page.
 */
final class PageContext {

  /**
   * The cacheability of everything read to describe this page.
   */
  private CacheableMetadata $cacheability;

  /**
   * Constructs a page context.
   *
   * @param string $siteUrl
   *   The absolute site URL, ending in a slash ("https://example.com/"). Every
   *   site-level @id hangs off it.
   * @param string $canonicalUrl
   *   The page's absolute canonical URL. It is the WebPage @id, and the
   *   page-level @ids (#article, #breadcrumb …) hang off it.
   * @param string $langcode
   *   The content language of the page.
   * @param string $siteName
   *   The site name.
   * @param bool $isFront
   *   Whether this is the front page.
   * @param \Drupal\Core\Entity\ContentEntityInterface|null $entity
   *   The entity this page is the canonical page of, if any.
   * @param string|null $title
   *   The page title, as a visitor reads it.
   * @param string|null $routeName
   *   The route name, for pieces that describe non-entity pages.
   */
  public function __construct(
    public readonly string $siteUrl,
    public readonly string $canonicalUrl,
    public readonly string $langcode,
    public readonly string $siteName,
    public readonly bool $isFront = FALSE,
    public readonly ?ContentEntityInterface $entity = NULL,
    public readonly ?string $title = NULL,
    public readonly ?string $routeName = NULL,
  ) {
    $this->cacheability = new CacheableMetadata();
    if ($entity) {
      $this->cacheability->addCacheableDependency($entity);
    }
  }

  /**
   * Records something the description of this page depends on.
   *
   * @param mixed $dependency
   *   The dependency. Anything that is not cacheable makes the page
   *   uncacheable, as with CacheableMetadata.
   *
   * @return $this
   */
  public function addCacheableDependency(mixed $dependency): self {
    $this->cacheability->addCacheableDependency($dependency);
    return $this;
  }

  /**
   * The cacheability of everything read to describe this page.
   *
   * @return \Drupal\Core\Cache\CacheableMetadata
   *   A copy; adding to it changes nothing here.
   */
  public function getCacheability(): CacheableMetadata {
    return CacheableMetadata::createFromObject($this->cacheability);
  }

}
