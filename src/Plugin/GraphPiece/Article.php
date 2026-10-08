<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Plugin\GraphPiece;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_seo\Attribute\GraphPiece;
use Drupal\neo_seo\Graph\GraphPieceBase;
use Drupal\neo_seo\Graph\PageContext;
use Drupal\neo_seo\Graph\SchemaIds;

/**
 * The page's Article, when its bundle or its editor says it is one.
 *
 * Authors are added by the author piece; without any, the Article carries
 * only its publisher.
 */
#[GraphPiece(
  id: 'article',
  label: new TranslatableMarkup('Article'),
  weight: -60,
)]
class Article extends GraphPieceBase {

  /**
   * Google's limit on an Article headline.
   */
  protected const HEADLINE_LENGTH = 110;

  /**
   * {@inheritdoc}
   */
  public function isNeeded(PageContext $context): bool {
    return $context->entity !== NULL && $this->headValues->resolve($context)->isArticle();
  }

  /**
   * {@inheritdoc}
   */
  public function generate(PageContext $context): array {
    $values = $this->headValues->resolve($context);
    $image = $values->image();
    $webPage = SchemaIds::ref($this->ids->webPage($context));
    return [
      [
        '@type' => $values->articleType,
        '@id' => $this->ids->article($context),
        'headline' => Unicode::truncate((string) $context->title, static::HEADLINE_LENGTH, TRUE, TRUE),
        'description' => $values->get('description'),
        'isPartOf' => $webPage,
        'mainEntityOfPage' => $webPage,
        'publisher' => SchemaIds::ref($this->ids->siteIdentity($context)),
        'image' => $image ? SchemaIds::ref($this->ids->primaryImage($context)) : NULL,
        'thumbnailUrl' => $image['url'] ?? NULL,
        'datePublished' => $values->datePublished,
        'dateModified' => $values->dateModified,
        'inLanguage' => $context->langcode,
      ],
    ];
  }

}
