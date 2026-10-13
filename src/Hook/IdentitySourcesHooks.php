<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\neo_seo\SiteIdentity\IdentitySources;

/**
 * Takes up the favicon and site settings when they are installed later.
 */
class IdentitySourcesHooks {

  public function __construct(
    protected IdentitySources $sources,
  ) {}

  /**
   * Implements hook_modules_installed().
   *
   * Installing neo_favicon or neo_site_settings after neo_seo turns its
   * source on, under the same rule as installing neo_seo after them: only
   * where neo_seo has nothing of its own.
   */
  #[Hook('modules_installed')]
  public function modulesInstalled(array $modules, bool $is_syncing): void {
    if (!$is_syncing && array_intersect($modules, ['neo_favicon', 'neo_site_settings'])) {
      $this->sources->adoptInConfig();
    }
  }

}
