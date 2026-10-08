<?php

declare(strict_types=1);

namespace Drupal\neo_seo_test\Plugin\GraphPiece;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\neo_seo\Attribute\GraphPiece;
use Drupal\neo_seo\Graph\GraphPieceBase;
use Drupal\neo_seo\Graph\PageContext;
use Drupal\neo_seo\Graph\SchemaIds;

/**
 * A piece another module adds, needed only while state says so.
 */
#[GraphPiece(id: 'neo_seo_test', weight: 50)]
class TestPiece extends GraphPieceBase {

  /**
   * {@inheritdoc}
   */
  public function isNeeded(PageContext $context): bool {
    return (bool) \Drupal::state()->get('neo_seo_test.piece');
  }

  /**
   * {@inheritdoc}
   */
  public function generate(PageContext $context): array {
    $context->addCacheableDependency((new CacheableMetadata())->addCacheTags(['neo_seo_test_tag']));
    return [
      [
        '@type' => 'Thing',
        '@id' => $this->ids->pageScoped($context, 'thing'),
        'name' => 'Test thing',
        'description' => '',
        'subjectOf' => SchemaIds::ref($this->ids->webPage($context)),
        'keywords' => ['one', NULL, 'two'],
      ],
    ];
  }

}
