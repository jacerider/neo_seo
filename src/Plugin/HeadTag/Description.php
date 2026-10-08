<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Plugin\HeadTag;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_seo\Attribute\HeadTag;
use Drupal\neo_seo\Head\HeadTagBase;
use Drupal\neo_seo\Head\HeadValues;

/**
 * The meta description.
 */
#[HeadTag(
  id: 'description',
  label: new TranslatableMarkup('Description'),
  weight: -90,
  fields: ['description' => new TranslatableMarkup('Description')],
)]
class Description extends HeadTagBase {

  /**
   * {@inheritdoc}
   */
  public function build(HeadValues $values): array {
    return $this->meta('description', $values->get('description'));
  }

}
