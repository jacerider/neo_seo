<?php

declare(strict_types=1);

namespace Drupal\neo_seo_analysis\Hook;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;

/**
 * Adds content analysis to the SEO panel.
 */
class AnalysisHooks {

  use StringTranslationTrait;

  /**
   * Implements hook_neo_seo_panel_badge_alter().
   *
   * The Alchemist top bar shows the page's SEO and readability scores.
   */
  #[Hook('neo_seo_panel_badge_alter')]
  public function neoSeoPanelBadgeAlter(array &$badge, ContentEntityInterface $entity): void {
    // The two scores, as a button group styled like the editor's scale and
    // device groups beside it; analysis.ts fills in the scores and marks the
    // open one active (is-active:btn-primary).
    $badge['neo_seo_analysis'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => ['class' => ['btn-group', 'flush', 'neo-seo-pills']],
    ];
    foreach (['seo' => $this->t('SEO'), 'readability' => $this->t('Read')] as $kind => $label) {
      $badge['neo_seo_analysis'][$kind] = [
        '#type' => 'html_tag',
        '#tag' => 'button',
        '#attributes' => [
          'type' => 'button',
          'class' => ['neo-seo-pill', 'btn', 'btn-xs', 'btn-outline', 'is-active:btn-primary', 'is-updating'],
          'data-neo-seo-score' => $kind,
        ],
        'dot' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#attributes' => ['class' => ['neo-seo-pill__dot'], 'aria-hidden' => 'true'],
        ],
        'label' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => $label,
          '#attributes' => ['class' => ['neo-seo-pill__label']],
        ],
        'value' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => '…',
          '#attributes' => ['class' => ['neo-seo-pill__value']],
        ],
      ];
    }
    $badge['#attached']['library'][] = 'neo_seo_analysis/panel';
  }

  /**
   * Implements hook_neo_seo_panel_alter().
   *
   * Both hosts get the analysis library and the endpoint that lists the
   * keyphrases other pages already use.
   */
  #[Hook('neo_seo_panel_alter')]
  public function neoSeoPanelAlter(array &$panel, ContentEntityInterface $entity, string $host): void {
    $query = ['entity_type' => $entity->getEntityTypeId()];
    if (!$entity->isNew()) {
      $query['exclude'] = $entity->id();
    }
    $panel['libraries'][] = 'neo_seo_analysis/panel';
    $panel['config']['analysis'] = [
      'keyphrasesUrl' => Url::fromRoute('neo_seo_analysis.keyphrases', [], ['query' => $query])
        ->toString(TRUE)->getGeneratedUrl(),
    ];
  }

}
