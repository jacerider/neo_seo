<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheableResponse;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\neo_seo\Schemamap\SchemamapBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The schemamap and its feeds.
 *
 * @see \Drupal\neo_seo\Schemamap\SchemamapBuilder
 */
final class SchemamapController extends ControllerBase {

  public function __construct(
    private readonly SchemamapBuilder $schemamap,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('neo_seo.schemamap'));
  }

  /**
   * The schemamap: one entry per feed page.
   */
  public function index(): CacheableResponse {
    if (!$this->schemamap->enabled()) {
      throw new NotFoundHttpException();
    }
    $cacheability = new CacheableMetadata();
    $entries = '';
    foreach ($this->schemamap->feeds($cacheability) as $feed) {
      $loc = Url::fromRoute('neo_seo.schemamap_feed', [
        'entity_type' => $feed['entity_type'],
        'bundle' => $feed['bundle'],
        'page' => $feed['page'],
      ], ['absolute' => TRUE])->toString(TRUE);
      $cacheability->addCacheableDependency($loc);
      // Schema Feeds names the content type in an element; Yoast's readers
      // take the attribute. Both, as Yoast SEO prints them.
      $entries .= '<url contentType="structuredData/schema.org">'
        . '<loc>' . Html::escape($loc->getGeneratedUrl()) . '</loc>'
        . ($feed['lastmod'] ? '<lastmod>' . gmdate('Y-m-d\TH:i:s\Z', $feed['lastmod']) . '</lastmod>' : '')
        . '<changefreq>daily</changefreq><priority>0.8</priority>'
        . '<sf:contentType>structuredData/schema.org</sf:contentType></url>';
    }
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
      . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:sf="http://schema.org/schemas/schemafeed/0.1">'
      . $entries . '</urlset>';
    $response = new CacheableResponse($xml, 200, ['Content-Type' => 'application/xml; charset=utf-8']);
    $response->addCacheableDependency($cacheability);
    return $response;
  }

  /**
   * A feed page: one JSON-LD node per line.
   */
  public function feed(string $entity_type, string $bundle, int $page = 1): CacheableResponse {
    if (!$this->schemamap->enabled() || $page < 1 || !$this->schemamap->hasBundle($entity_type, $bundle)) {
      throw new NotFoundHttpException();
    }
    $cacheability = new CacheableMetadata();
    $lines = [];
    foreach ($this->schemamap->nodes($entity_type, $bundle, $page, $cacheability) as $node) {
      $lines[] = json_encode(['@context' => 'https://schema.org'] + $node, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    $response = new CacheableResponse(implode("\n", $lines) . ($lines ? "\n" : ''), 200, [
      'Content-Type' => 'application/json; charset=utf-8',
    ]);
    $response->addCacheableDependency($cacheability);
    return $response;
  }

}
