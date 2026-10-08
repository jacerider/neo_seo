<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Schema;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * The page types and article types a page may carry.
 *
 * Yoast's lists, which are what search engines and schema consumers are used
 * to reading, minus FAQPage and QAPage: those exist to earn FAQ and Q&A rich
 * results, which need markup a page type alone cannot supply.
 */
final class SchemaTypes {

  /**
   * The article type that means "no Article on this page".
   */
  public const NO_ARTICLE = 'None';

  /**
   * WebPage types, schema.org name => label.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   The options.
   */
  public static function pageTypes(): array {
    return [
      'WebPage' => new TranslatableMarkup('Web page'),
      'ItemPage' => new TranslatableMarkup('Item page'),
      'AboutPage' => new TranslatableMarkup('About page'),
      'ProfilePage' => new TranslatableMarkup('Profile page'),
      'ContactPage' => new TranslatableMarkup('Contact page'),
      'CollectionPage' => new TranslatableMarkup('Collection page'),
      'SearchResultsPage' => new TranslatableMarkup('Search results page'),
      'MedicalWebPage' => new TranslatableMarkup('Medical web page'),
      'CheckoutPage' => new TranslatableMarkup('Checkout page'),
      'RealEstateListing' => new TranslatableMarkup('Real estate listing'),
    ];
  }

  /**
   * Article types, schema.org name => label, with None first.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   The options.
   */
  public static function articleTypes(): array {
    return [
      self::NO_ARTICLE => new TranslatableMarkup('None'),
      'Article' => new TranslatableMarkup('Article'),
      'BlogPosting' => new TranslatableMarkup('Blog post'),
      'NewsArticle' => new TranslatableMarkup('News article'),
      'TechArticle' => new TranslatableMarkup('Technical article'),
      'ScholarlyArticle' => new TranslatableMarkup('Scholarly article'),
      'Report' => new TranslatableMarkup('Report'),
      'SocialMediaPosting' => new TranslatableMarkup('Social media posting'),
      'AdvertiserContentArticle' => new TranslatableMarkup('Advertiser content article'),
      'SatiricalArticle' => new TranslatableMarkup('Satirical article'),
    ];
  }

}
