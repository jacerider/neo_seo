<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Plugin\GraphPiece;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_seo\Alchemist\ComponentPieceCollector;
use Drupal\neo_seo\Attribute\GraphPiece;
use Drupal\neo_seo\Graph\GraphPieceBase;
use Drupal\neo_seo\Graph\PageContext;
use Drupal\neo_seo\Graph\SchemaIds;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The pieces Alchemist components on the page declare.
 *
 * One node per declaring component instance, at `{canonical}#{type}-{uuid}`,
 * part of the page. A node missing what its type requires is left out and
 * logged, never emitted invalid.
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
    $nodes = [];
    foreach ($this->collector->collect($context) as $piece) {
      $properties = $piece['values'] + $this->fallbacks($piece['type'], $context);
      $missing = ComponentPieceCollector::missing($piece['type'], $properties);
      if ($missing) {
        $this->collector->reportDropped($context, $piece, $missing);
        continue;
      }
      $nodes[] = [
        '@type' => $piece['type'],
        '@id' => $this->ids->pageScoped($context, strtolower($piece['type']) . '-' . $piece['uuid']),
      ] + $properties + [
        'isPartOf' => SchemaIds::ref($this->ids->webPage($context)),
        'inLanguage' => $context->langcode,
      ];
    }
    return $nodes;
  }

  /**
   * Values a type may take from the page when its component has none.
   *
   * A video is published with its page unless it says otherwise, and is
   * named for the page when it has no title of its own.
   */
  protected function fallbacks(string $type, PageContext $context): array {
    if ($type !== 'VideoObject') {
      return [];
    }
    $values = $this->headValues->resolve($context);
    return array_filter([
      'uploadDate' => $values->datePublished,
      'name' => $context->title,
    ]);
  }

}
