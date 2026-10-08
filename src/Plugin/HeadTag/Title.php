<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Plugin\HeadTag;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_seo\Attribute\HeadTag;
use Drupal\neo_seo\Head\HeadTagBase;
use Drupal\neo_seo\Head\HeadValues;

/**
 * The document <title>.
 *
 * It prints nothing into html_head: the theme owns the <title> element, so
 * neo_seo sets its text in hook_preprocess_html() instead.
 */
#[HeadTag(
  id: 'title',
  label: new TranslatableMarkup('Title'),
  weight: -100,
  fields: ['title' => new TranslatableMarkup('Title')],
)]
class Title extends HeadTagBase {

  /**
   * {@inheritdoc}
   */
  public function build(HeadValues $values): array {
    return [];
  }

}
