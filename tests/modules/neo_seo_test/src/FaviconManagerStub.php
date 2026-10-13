<?php

declare(strict_types=1);

namespace Drupal\neo_seo_test;

/**
 * Stands in for neo_favicon's manager: the icons a test puts in state.
 */
class FaviconManagerStub {

  /**
   * The favicon's PNGs: uri => ['width' => …, 'height' => …].
   */
  public function getImages(): array {
    return \Drupal::state()->get('neo_seo_test.favicon_images', []);
  }

}
