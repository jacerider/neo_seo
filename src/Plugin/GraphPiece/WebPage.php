<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Plugin\GraphPiece;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_seo\Attribute\GraphPiece;
use Drupal\neo_seo\Graph\GraphPieceBase;
use Drupal\neo_seo\Graph\PageContext;
use Drupal\neo_seo\Graph\SchemaIds;

/**
 * The page itself, part of the WebSite. Its id is the canonical URL.
 */
#[GraphPiece(
  id: 'webpage',
  label: new TranslatableMarkup('Web page'),
  weight: -80,
)]
class WebPage extends GraphPieceBase {

  /**
   * {@inheritdoc}
   */
  public function generate(PageContext $context): array {
    return [
      [
        '@type' => 'WebPage',
        '@id' => $this->ids->webPage($context),
        'url' => $context->canonicalUrl,
        'name' => $context->title,
        'isPartOf' => SchemaIds::ref($this->ids->website($context)),
        'about' => $context->isFront ? SchemaIds::ref($this->ids->siteIdentity($context)) : NULL,
        'inLanguage' => $context->langcode,
      ],
    ];
  }

}
