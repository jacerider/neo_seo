<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Plugin\GraphPiece;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_seo\Attribute\GraphPiece;
use Drupal\neo_seo\Graph\GraphPieceBase;
use Drupal\neo_seo\Graph\PageContext;
use Drupal\neo_seo\Graph\SchemaIds;

/**
 * The WebSite, published by the site identity.
 *
 * No SearchAction: Google retired the sitelinks search box it fed in 2024.
 */
#[GraphPiece(
  id: 'website',
  label: new TranslatableMarkup('Website'),
  weight: -90,
)]
class WebSite extends GraphPieceBase {

  /**
   * {@inheritdoc}
   */
  public function generate(PageContext $context): array {
    return [
      [
        '@type' => 'WebSite',
        '@id' => $this->ids->website($context),
        'url' => $context->siteUrl,
        'name' => $context->siteName,
        'publisher' => SchemaIds::ref($this->ids->siteIdentity($context)),
        'inLanguage' => $context->langcode,
      ],
    ];
  }

}
