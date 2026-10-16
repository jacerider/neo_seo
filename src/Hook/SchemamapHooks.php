<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Url;
use Drupal\neo_seo\Schemamap\SchemamapBuilder;

/**
 * Announces the schemamap in robots.txt, where robots.txt is generated.
 */
class SchemamapHooks {

  /**
   * Constructs the hooks.
   *
   * @param \Drupal\neo_seo\Schemamap\SchemamapBuilder $schemamap
   *   The schemamap.
   */
  public function __construct(
    protected SchemamapBuilder $schemamap,
  ) {}

  /**
   * Implements hook_robotstxt().
   *
   * The robotstxt module asks for it. A site serving a static robots.txt
   * adds the line itself: `Sitemap: https://example.com/schemamap.xml`.
   */
  #[Hook('robotstxt')]
  public function robotstxt(): array {
    if (!$this->schemamap->enabled()) {
      return [];
    }
    return ['Sitemap: ' . Url::fromRoute('neo_seo.schemamap', [], ['absolute' => TRUE])->toString()];
  }

}
