<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Plugin\HeadTag;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_seo\Attribute\HeadTag;
use Drupal\neo_seo\Head\HeadTagBase;
use Drupal\neo_seo\Head\HeadValues;

/**
 * Open Graph: what Facebook, LinkedIn and most chat apps show for a link.
 */
#[HeadTag(
  id: 'open_graph',
  label: new TranslatableMarkup('Open Graph'),
  weight: 0,
  fields: [
    'social_title' => new TranslatableMarkup('Social title'),
    'og_type' => new TranslatableMarkup('Open Graph type'),
    'image' => new TranslatableMarkup('Share image'),
  ],
)]
class OpenGraph extends HeadTagBase {

  /**
   * {@inheritdoc}
   */
  public function build(HeadValues $values): array {
    $context = $values->context;
    // An article is an article and a profile is a profile, whatever the
    // template says; every other page takes the template's type.
    $type = match (TRUE) {
      $values->isArticle() => 'article',
      $values->pageType === 'ProfilePage' => 'profile',
      default => $values->get('og_type') ?: 'website',
    };
    $tags = array_merge(
      $this->meta('og:site_name', $context->siteName, 'property'),
      $this->meta('og:type', $type, 'property'),
      $this->meta('og:url', $context->canonicalUrl, 'property'),
      $this->meta('og:title', $values->get('social_title') ?: $values->get('title'), 'property'),
      $this->meta('og:description', $values->get('description'), 'property'),
      $this->meta('og:locale', static::locale($context->langcode), 'property'),
    );
    if ($image = $values->image()) {
      $tags = array_merge(
        $tags,
        $this->meta('og:image', $image['url'], 'property'),
        $this->meta('og:image:width', $image['width'], 'property'),
        $this->meta('og:image:height', $image['height'], 'property'),
        $this->meta('og:image:alt', $image['alt'], 'property'),
      );
    }
    if ($type === 'article') {
      $tags = array_merge(
        $tags,
        $this->meta('article:published_time', $values->datePublished, 'property'),
        $this->meta('article:modified_time', $values->dateModified, 'property'),
      );
    }
    return $tags;
  }

  /**
   * An Open Graph locale for a langcode: `en` → `en_US`, `pt-br` → `pt_BR`.
   *
   * Open Graph wants language_TERRITORY. A bare language gets its most common
   * territory where that is unambiguous, and itself doubled otherwise (`fr` →
   * `fr_FR`), which is what Facebook expects for the major languages.
   */
  public static function locale(string $langcode): string {
    $parts = preg_split('/[-_]/', $langcode);
    if (count($parts) > 1) {
      return strtolower($parts[0]) . '_' . strtoupper($parts[1]);
    }
    $territories = [
      'en' => 'US',
      'ja' => 'JP',
      'zh' => 'CN',
      'ko' => 'KR',
      'sv' => 'SE',
      'da' => 'DK',
      'el' => 'GR',
      'uk' => 'UA',
      'he' => 'IL',
      'hi' => 'IN',
      'vi' => 'VN',
      'cs' => 'CZ',
      'ar' => 'AR',
    ];
    $language = strtolower($parts[0]);
    return $language . '_' . ($territories[$language] ?? strtoupper($language));
  }

}
