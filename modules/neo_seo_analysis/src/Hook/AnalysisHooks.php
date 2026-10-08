<?php

declare(strict_types=1);

namespace Drupal\neo_seo_analysis\Hook;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Url;

/**
 * Adds content analysis to the SEO panel.
 */
class AnalysisHooks {

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
