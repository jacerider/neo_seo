<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Plugin\GraphPiece;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_seo\Attribute\GraphPiece;
use Drupal\neo_seo\Graph\GraphPieceBase;
use Drupal\neo_seo\Graph\PageContext;

/**
 * What the site represents: the node everything else is published by.
 *
 * Until site identity has settings of its own, this is an Organization named
 * after the site, so WebSite.publisher has somewhere to land.
 */
#[GraphPiece(
  id: 'site_identity',
  label: new TranslatableMarkup('Site identity'),
  weight: -100,
)]
class SiteIdentity extends GraphPieceBase {

  /**
   * {@inheritdoc}
   */
  public function generate(PageContext $context): array {
    return [
      [
        '@type' => 'Organization',
        '@id' => $this->ids->siteIdentity($context),
        'name' => $context->siteName,
        'url' => $context->siteUrl,
      ],
    ];
  }

}
