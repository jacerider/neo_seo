<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Plugin\GraphPiece;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_seo\Attribute\GraphPiece;
use Drupal\neo_seo\Graph\BreadcrumbTrail;
use Drupal\neo_seo\Graph\GraphPieceBase;
use Drupal\neo_seo\Graph\PageContext;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The BreadcrumbList for the trail visitors see. Not on the front page.
 */
#[GraphPiece(
  id: 'breadcrumb',
  label: new TranslatableMarkup('Breadcrumb'),
  weight: -50,
)]
class Breadcrumb extends GraphPieceBase {

  /**
   * The breadcrumb trail.
   */
  protected BreadcrumbTrail $breadcrumbTrail;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->breadcrumbTrail = $container->get('neo_seo.breadcrumb_trail');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function isNeeded(PageContext $context): bool {
    return (bool) $this->breadcrumbTrail->items($context);
  }

  /**
   * {@inheritdoc}
   */
  public function generate(PageContext $context): array {
    $elements = [];
    foreach ($this->breadcrumbTrail->items($context) as $index => $item) {
      $elements[] = [
        '@type' => 'ListItem',
        'position' => $index + 1,
        'name' => $item['name'],
        'item' => $item['url'],
      ];
    }
    return [
      [
        '@type' => 'BreadcrumbList',
        '@id' => $this->ids->breadcrumb($context),
        'itemListElement' => $elements,
      ],
    ];
  }

}
