<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Head;

use Drupal\Component\Plugin\PluginInspectionInterface;

/**
 * Something neo_seo prints in the page's <head>.
 */
interface HeadTagInterface extends PluginInspectionInterface {

  /**
   * The head elements for a page.
   *
   * @param \Drupal\neo_seo\Head\HeadValues $values
   *   The page's resolved values: every template field, the image and the page
   *   context.
   *
   * @return array<int, array{0: array, 1: string}>
   *   A list of `html_head` attachments: [render element, unique key].
   */
  public function build(HeadValues $values): array;

}
