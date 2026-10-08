<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Mapping;

use Drupal\neo_seo\Schema\SchemaTypes;

/**
 * What a bundle is, in schema terms, and which fields describe it.
 *
 * Stored as the bundle's `neo_seo` third-party settings, so it exports with
 * the bundle. A bundle with nothing stored is a WebPage with no Article.
 */
final class BundleMapping {

  /**
   * The settings a mapping stores, with their defaults.
   */
  public const DEFAULTS = [
    'page_type' => 'WebPage',
    'page_types' => [],
    'article_type' => SchemaTypes::NO_ARTICLE,
    'article_types' => [],
    'image_field' => '',
    'published_field' => '',
    'modified_field' => '',
    'author_field' => '',
    'job_title_field' => '',
    'same_as_field' => '',
  ];

  /**
   * Constructs a mapping.
   *
   * @param string $pageType
   *   The bundle's page type.
   * @param string[] $pageTypes
   *   The page types an editor may choose; empty means only the default.
   * @param string $articleType
   *   The bundle's article type, or SchemaTypes::NO_ARTICLE.
   * @param string[] $articleTypes
   *   The article types an editor may choose; empty means only the default.
   * @param string $imageField
   *   The field that supplies the primary image, or ''.
   * @param string $publishedField
   *   The field that supplies the date published, or '' for `created`.
   * @param string $modifiedField
   *   The field that supplies the date modified, or '' for `changed`.
   * @param string $authorField
   *   The reference field whose targets are the authors, or ''.
   * @param string $jobTitleField
   *   On a person bundle, the field that supplies the job title, or ''.
   * @param string $sameAsField
   *   On a person bundle, the link field of their social profiles, or ''.
   */
  public function __construct(
    public readonly string $pageType = 'WebPage',
    public readonly array $pageTypes = [],
    public readonly string $articleType = SchemaTypes::NO_ARTICLE,
    public readonly array $articleTypes = [],
    public readonly string $imageField = '',
    public readonly string $publishedField = '',
    public readonly string $modifiedField = '',
    public readonly string $authorField = '',
    public readonly string $jobTitleField = '',
    public readonly string $sameAsField = '',
  ) {}

  /**
   * A mapping from stored settings; unknown types fall back to the defaults.
   *
   * @param array $settings
   *   The third-party settings.
   */
  public static function fromSettings(array $settings): self {
    $settings += self::DEFAULTS;
    $pageTypes = array_keys(SchemaTypes::pageTypes());
    $articleTypes = array_keys(SchemaTypes::articleTypes());
    return new self(
      pageType: in_array($settings['page_type'], $pageTypes, TRUE) ? $settings['page_type'] : 'WebPage',
      pageTypes: array_values(array_intersect((array) $settings['page_types'], $pageTypes)),
      articleType: in_array($settings['article_type'], $articleTypes, TRUE) ? $settings['article_type'] : SchemaTypes::NO_ARTICLE,
      articleTypes: array_values(array_intersect((array) $settings['article_types'], $articleTypes)),
      imageField: (string) $settings['image_field'],
      publishedField: (string) $settings['published_field'],
      modifiedField: (string) $settings['modified_field'],
      authorField: (string) $settings['author_field'],
      jobTitleField: (string) $settings['job_title_field'],
      sameAsField: (string) $settings['same_as_field'],
    );
  }

  /**
   * The page types an editor may choose: the allowed set plus the default.
   *
   * @return string[]
   *   The types.
   */
  public function allowedPageTypes(): array {
    return array_values(array_unique([$this->pageType, ...$this->pageTypes]));
  }

  /**
   * The article types an editor may choose: the allowed set plus the default.
   *
   * @return string[]
   *   The types.
   */
  public function allowedArticleTypes(): array {
    return array_values(array_unique([$this->articleType, ...$this->articleTypes]));
  }

  /**
   * The page type for a page, honouring an editor's choice only if allowed.
   */
  public function resolvePageType(?string $override): string {
    return $override && in_array($override, $this->allowedPageTypes(), TRUE) ? $override : $this->pageType;
  }

  /**
   * The article type for a page, honouring an editor's choice only if allowed.
   */
  public function resolveArticleType(?string $override): string {
    return $override && in_array($override, $this->allowedArticleTypes(), TRUE) ? $override : $this->articleType;
  }

}
