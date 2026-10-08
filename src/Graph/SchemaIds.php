<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Graph;

/**
 * Hands out every @id in the graph.
 *
 * The suffixes are Yoast's, so a graph reads the same as one from the most
 * widely deployed generator and tools that know Yoast's shape understand ours.
 * Site-level things hang off the site URL; page-level things hang off the
 * canonical URL, which is itself the WebPage's id.
 *
 * Nothing else in neo_seo concatenates an id. Two pieces referring to the same
 * thing ask here and get the same string, which is what makes a reference
 * from one piece land on a node another piece emitted.
 */
final class SchemaIds {

  public const ORGANIZATION = '#organization';
  public const LOGO = '#/schema/logo/image/';
  public const WEBSITE = '#website';
  public const ARTICLE = '#article';
  public const PRIMARY_IMAGE = '#primaryimage';
  public const BREADCRUMB = '#breadcrumb';
  public const PERSON = '#/schema/person/';

  /**
   * The site identity (Organization or Person): `{site}#organization`.
   */
  public function siteIdentity(PageContext $context): string {
    return $context->siteUrl . self::ORGANIZATION;
  }

  /**
   * The site identity's logo: `{site}#/schema/logo/image/`.
   */
  public function logo(PageContext $context): string {
    return $context->siteUrl . self::LOGO;
  }

  /**
   * The WebSite: `{site}#website`.
   */
  public function website(PageContext $context): string {
    return $context->siteUrl . self::WEBSITE;
  }

  /**
   * The WebPage: the canonical URL itself.
   */
  public function webPage(PageContext $context): string {
    return $context->canonicalUrl;
  }

  /**
   * The page's Article: `{canonical}#article`.
   */
  public function article(PageContext $context): string {
    return $context->canonicalUrl . self::ARTICLE;
  }

  /**
   * The page's primary image: `{canonical}#primaryimage`.
   */
  public function primaryImage(PageContext $context): string {
    return $context->canonicalUrl . self::PRIMARY_IMAGE;
  }

  /**
   * The page's BreadcrumbList: `{canonical}#breadcrumb`.
   */
  public function breadcrumb(PageContext $context): string {
    return $context->canonicalUrl . self::BREADCRUMB;
  }

  /**
   * A person: `{site}#/schema/person/{key}`.
   *
   * Keyed by something stable (an entity uuid), never by URL, so a person keeps
   * one id on their own page and as an author everywhere else, and keeps it
   * when their alias changes.
   *
   * @param \Drupal\neo_seo\Graph\PageContext $context
   *   The page.
   * @param string $key
   *   The stable key, typically the person entity's uuid.
   */
  public function person(PageContext $context, string $key): string {
    return $context->siteUrl . self::PERSON . $key;
  }

  /**
   * A node scoped to the page: `{canonical}#{fragment}`.
   *
   * For pieces whose nodes have no Yoast constant, such as a component's
   * VideoObject. The fragment must be unique on the page.
   */
  public function pageScoped(PageContext $context, string $fragment): string {
    return $context->canonicalUrl . '#' . ltrim($fragment, '#');
  }

  /**
   * A reference to a node: `{"@id": …}`.
   *
   * @param string $id
   *   The id.
   *
   * @return array{"@id": string}
   *   The reference.
   */
  public static function ref(string $id): array {
    return ['@id' => $id];
  }

}
