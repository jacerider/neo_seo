<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Plugin\views\field;

use Drupal\views\Attribute\ViewsField;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;

/**
 * An analysis score: 0–100, or nothing for a page never analysed.
 *
 * The numeric handler shows a missing score as 0, which reads as a failing
 * page; a page nobody has analysed gets the field's "No results" text
 * instead, and a real 0 still shows as 0.
 */
#[ViewsField('neo_seo_score')]
class SeoScore extends FieldPluginBase {

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    $value = $this->getValue($values);
    if ($value === NULL || $value === '') {
      return '';
    }
    return (string) (int) $value;
  }

}
