<?php

declare(strict_types=1);

namespace Drupal\neo_seo_test\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\neo_seo\Graph\PageContext;

/**
 * Test hooks.
 */
class NeoSeoTestHooks {

  /**
   * Implements hook_neo_seo_graph_alter().
   */
  #[Hook('neo_seo_graph_alter')]
  public function graphAlter(array &$nodes, PageContext $context): void {
    if (!\Drupal::state()->get('neo_seo_test.alter')) {
      return;
    }
    foreach ($nodes as &$node) {
      if ($node['@type'] === 'WebSite') {
        $node['alternateName'] = 'Altered';
      }
    }
  }

}
