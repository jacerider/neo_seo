<?php

declare(strict_types=1);

namespace Drupal\neo_seo_test\Plugin\HeadTag;

use Drupal\neo_seo\Attribute\HeadTag;
use Drupal\neo_seo\Head\HeadTagBase;
use Drupal\neo_seo\Head\HeadValues;

/**
 * A head tag another module adds, with a template field of its own.
 */
#[HeadTag(
  id: 'neo_seo_test',
  weight: 200,
  fields: ['test_field' => 'Test field'],
)]
class TestTag extends HeadTagBase {

  /**
   * {@inheritdoc}
   */
  public function build(HeadValues $values): array {
    return $this->meta('neo-seo-test', $values->get('test_field'));
  }

}
