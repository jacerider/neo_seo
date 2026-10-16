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
   * adds the line itself: `Schemamap: https://example.com/schemamap.xml`.
   * The directive is Schema Feeds' own, not `Sitemap:`: a schemamap lists
   * JSON-LD feeds, which a search engine reading sitemaps cannot use.
   *
   * @see https://github.com/nlweb-ai/website/blob/main/SCHEMA_SPEC.md
   */
  #[Hook('robotstxt')]
  public function robotstxt(): array {
    if (!$this->schemamap->enabled()) {
      return [];
    }
    return ['Schemamap: ' . Url::fromRoute('neo_seo.schemamap', [], ['absolute' => TRUE])->toString()];
  }

}
