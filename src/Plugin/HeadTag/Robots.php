<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Plugin\HeadTag;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_seo\Attribute\HeadTag;
use Drupal\neo_seo\Head\HeadTagBase;
use Drupal\neo_seo\Head\HeadValues;

/**
 * The robots directives, printed only when there are some.
 */
#[HeadTag(
  id: 'robots',
  label: new TranslatableMarkup('Robots'),
  weight: -70,
  fields: ['robots' => new TranslatableMarkup('Robots')],
)]
class Robots extends HeadTagBase {

  /**
   * {@inheritdoc}
   */
  public function build(HeadValues $values): array {
    return $this->meta('robots', $values->get('robots'));
  }

}
