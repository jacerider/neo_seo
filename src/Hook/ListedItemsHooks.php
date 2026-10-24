<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\neo_seo\Graph\ListedItems;
use Drupal\views\ViewExecutable;

/**
 * Keeps the rows a Views page executed, for the ItemList that describes them.
 */
class ListedItemsHooks {

  /**
   * Constructs the hooks.
   *
   * @param \Drupal\neo_seo\Graph\ListedItems $listedItems
   *   What a collection page lists.
   */
  public function __construct(
    protected ListedItems $listedItems,
  ) {}

  /**
   * Implements hook_views_post_execute().
   */
  #[Hook('views_post_execute')]
  public function viewsPostExecute(ViewExecutable $view): void {
    $this->listedItems->capture($view);
  }

}
