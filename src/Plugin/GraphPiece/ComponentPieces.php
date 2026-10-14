<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Plugin\GraphPiece;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_seo\Alchemist\ComponentPieceCollector;
use Drupal\neo_seo\Attribute\GraphPiece;
use Drupal\neo_seo\Graph\GraphPieceBase;
use Drupal\neo_seo\Graph\PageContext;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The pieces Alchemist components on the page declare.
 *
 * The nodes each component's `seo` declaration describes: at the @id it
 * declares, else `{canonical}#{type}-{uuid}` (and `-{row}` for a node an
 * `@each` repeats). A node missing what its type or its declaration requires
 * is left out and logged, never emitted invalid.
 */
#[GraphPiece(
  id: 'component_pieces',
  label: new TranslatableMarkup('Component pieces'),
  weight: -30,
)]
class ComponentPieces extends GraphPieceBase {

  /**
   * The collector.
   */
  protected ComponentPieceCollector $collector;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->collector = $container->get('neo_seo.component_pieces');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function isNeeded(PageContext $context): bool {
    return $context->entity !== NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function generate(PageContext $context): array {
    $pieces = $this->collector->collect($context);
    if (!$pieces) {
      return [];
    }
    $result = $this->collector->finish($pieces, $context, [
      'datePublished' => $this->headValues->resolve($context)->datePublished,
    ]);
    foreach ($result['dropped'] as $piece) {
      $this->collector->reportDropped($context, $piece);
    }
    return $result['nodes'];
  }

}
