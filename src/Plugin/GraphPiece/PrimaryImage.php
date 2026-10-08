<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Plugin\GraphPiece;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_seo\Attribute\GraphPiece;
use Drupal\neo_seo\Graph\GraphPieceBase;
use Drupal\neo_seo\Graph\PageContext;

/**
 * The page's primary image — the same image as its share image.
 */
#[GraphPiece(
  id: 'primary_image',
  label: new TranslatableMarkup('Primary image'),
  weight: -70,
)]
class PrimaryImage extends GraphPieceBase {

  /**
   * {@inheritdoc}
   */
  public function isNeeded(PageContext $context): bool {
    return $this->headValues->resolve($context)->image() !== NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function generate(PageContext $context): array {
    $image = $this->headValues->resolve($context)->image();
    return [
      [
        '@type' => 'ImageObject',
        '@id' => $this->ids->primaryImage($context),
        'url' => $image['url'],
        'contentUrl' => $image['url'],
        'width' => $image['width'],
        'height' => $image['height'],
        'caption' => $image['alt'],
        'inLanguage' => $context->langcode,
      ],
    ];
  }

}
