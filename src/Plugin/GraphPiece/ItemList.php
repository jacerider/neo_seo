<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Plugin\GraphPiece;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_seo\Attribute\GraphPiece;
use Drupal\neo_seo\Graph\GraphPieceBase;
use Drupal\neo_seo\Graph\ListedItems;
use Drupal\neo_seo\Graph\PageContext;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The ItemList of a collection page: a position and a URL per listed page.
 *
 * Google's summary-page form: the rows on the page being viewed, each linking
 * to its own page. The WebPage's main entity.
 */
#[GraphPiece(
  id: 'item_list',
  label: new TranslatableMarkup('Item list'),
  weight: -45,
)]
class ItemList extends GraphPieceBase {

  /**
   * What a collection page lists.
   */
  protected ListedItems $listedItems;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->listedItems = $container->get('neo_seo.listed_items');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function isNeeded(PageContext $context): bool {
    return (bool) $this->listedItems->items($context);
  }

  /**
   * {@inheritdoc}
   */
  public function generate(PageContext $context): array {
    $elements = [];
    foreach ($this->listedItems->items($context) as $item) {
      $elements[] = [
        '@type' => 'ListItem',
        'position' => $item['position'],
        'url' => $item['url'],
      ];
    }
    return [
      [
        '@type' => 'ItemList',
        '@id' => $this->ids->itemList($context),
        'itemListElement' => $elements,
      ],
    ];
  }

}
