<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Plugin\HeadTag;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_seo\Attribute\HeadTag;
use Drupal\neo_seo\Head\HeadTagBase;
use Drupal\neo_seo\Head\HeadValues;

/**
 * The canonical link: the same URL as the WebPage's @id and og:url.
 *
 * Not a template field. A page has one canonical URL, decided by the page
 * context, and the graph keys the page by it; letting a template change one
 * and not the other would split the page in two.
 */
#[HeadTag(
  id: 'canonical',
  label: new TranslatableMarkup('Canonical URL'),
  weight: -80,
)]
class Canonical extends HeadTagBase {

  /**
   * {@inheritdoc}
   */
  public function build(HeadValues $values): array {
    return [
      [
        [
          '#type' => 'html_tag',
          '#tag' => 'link',
          '#attributes' => ['rel' => 'canonical', 'href' => $values->context->canonicalUrl],
        ],
        'neo_seo_canonical',
      ],
    ];
  }

}
