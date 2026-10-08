<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\neo_seo\SeoOverrides;

/**
 * Views data for the SEO overrides' columns.
 *
 * Entity views data exposes each column of the base field, but through the
 * generic field handler, which needs a formatter the overrides field type
 * does not have, and under titles like "SEO (seo_score)". The columns are
 * plain values, so they get plain handlers: a score handler that tells "never
 * analysed" from 0, yes/no for the flags, text for the rest.
 */
class ViewsDataHooks {

  use StringTranslationTrait;

  /**
   * Implements hook_views_data_alter().
   */
  #[Hook('views_data_alter')]
  public function viewsDataAlter(array &$data): void {
    $columns = [
      'title' => ['standard', 'string', $this->t('SEO title')],
      'description' => ['standard', 'string', $this->t('SEO description')],
      'keyphrase' => ['standard', 'string', $this->t('Focus keyphrase')],
      'page_type' => ['standard', 'string', $this->t('Page type')],
      'article_type' => ['standard', 'string', $this->t('Article type')],
      'noindex' => ['boolean', 'boolean', $this->t('Hidden from search results')],
      'cornerstone' => ['boolean', 'boolean', $this->t('Cornerstone content')],
      'seo_score' => ['neo_seo_score', 'numeric', $this->t('SEO score')],
      'readability_score' => ['neo_seo_score', 'numeric', $this->t('Readability score')],
    ];
    foreach ($data as &$table) {
      foreach ($columns as $column => [$field, $filter, $title]) {
        $key = SeoOverrides::FIELD . '__' . $column;
        if (!isset($table[$key])) {
          continue;
        }
        $table[$key]['title'] = $title;
        $table[$key]['help'] = $this->t('From the page’s SEO overrides.');
        $table[$key]['field'] = ['id' => $field];
        $table[$key]['filter']['id'] = $filter;
        $table[$key]['sort']['id'] = 'standard';
        if ($filter === 'boolean') {
          $table[$key]['filter']['label'] = $title;
          $table[$key]['filter']['type'] = 'yes-no';
          // A page with no overrides stores NULL: "No" includes it.
          $table[$key]['filter']['accept null'] = TRUE;
        }
      }
    }
  }

}
