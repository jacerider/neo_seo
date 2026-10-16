<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Schemamap;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\neo_seo\Graph\GraphBuilder;
use Drupal\neo_seo\Graph\PageContextFactory;
use Drupal\neo_seo\Head\HeadValuesResolver;

/**
 * The site's schema, every page's graph, as feeds for a schemamap.
 *
 * `/schemamap.xml` lists one feed per page of each bundle with per-page SEO;
 * a feed holds the JSON-LD nodes of up to a page of that bundle's published
 * entities, each graph built as an anonymous visitor sees the entity's page.
 * A node many pages share (the organization, the website) is listed once.
 * What a visitor may not see, and a page kept out of search results
 * (noindex), is left out.
 *
 * The format is Yoast SEO's schema aggregation, which the Yoast schema
 * visualizer and NLWeb agents read: a sitemap `urlset` whose entries are
 * `contentType="structuredData/schema.org"`, each feed one JSON-LD node per
 * line.
 *
 * Building a page's graph resolves what its layout shows, so each entity's
 * nodes are cached, invalidated by what they were built from.
 */
class SchemamapBuilder {

  /**
   * Constructs the builder.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $bundleInfo
   *   The bundle info.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\neo_seo\Graph\PageContextFactory $contexts
   *   Builds an entity's page context.
   * @param \Drupal\neo_seo\Graph\GraphBuilder $graphBuilder
   *   Builds a page's graph.
   * @param \Drupal\neo_seo\Head\HeadValuesResolver $headValues
   *   A page's head values, for its robots.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   Where each entity's nodes are kept.
   * @param \Drupal\Core\Session\AccountSwitcherInterface $accountSwitcher
   *   Builds as an anonymous visitor.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   Building a graph may render.
   * @param int $perPage
   *   How many entities a feed page holds.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityTypeBundleInfoInterface $bundleInfo,
    protected ConfigFactoryInterface $configFactory,
    protected PageContextFactory $contexts,
    protected GraphBuilder $graphBuilder,
    protected HeadValuesResolver $headValues,
    protected CacheBackendInterface $cache,
    protected AccountSwitcherInterface $accountSwitcher,
    protected RendererInterface $renderer,
    protected int $perPage = 100,
  ) {}

  /**
   * Whether the site publishes a schemamap.
   */
  public function enabled(): bool {
    return (bool) ($this->configFactory->get('neo_seo.settings')->get('schemamap') ?? TRUE);
  }

  /**
   * The feeds the schemamap lists.
   *
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects what the list depends on.
   *
   * @return array<int, array{entity_type: string, bundle: string, page: int, lastmod: ?int}>
   *   One per page of each bundle with published entities; lastmod is the
   *   bundle's latest change, when its entities record one.
   */
  public function feeds(CacheableMetadata $cacheability): array {
    $cacheability->addCacheableDependency($this->configFactory->get('neo_seo.settings'));
    $feeds = [];
    foreach ($this->bundles() as [$entityTypeId, $bundle]) {
      $cacheability->addCacheTags([$entityTypeId . '_list']);
      // A bundle kept out of search results has nothing to list.
      if ($this->bundleNoindex($entityTypeId, $bundle, $cacheability)) {
        continue;
      }
      $count = (int) $this->query($entityTypeId, $bundle)->count()->execute();
      if (!$count) {
        continue;
      }
      $lastmod = $this->lastChanged($entityTypeId, $bundle);
      for ($page = 1; $page <= (int) ceil($count / $this->perPage); $page++) {
        $feeds[] = ['entity_type' => $entityTypeId, 'bundle' => $bundle, 'page' => $page, 'lastmod' => $lastmod];
      }
    }
    return $feeds;
  }

  /**
   * Whether a bundle has a feed.
   */
  public function hasBundle(string $entityTypeId, string $bundle): bool {
    return in_array([$entityTypeId, $bundle], $this->bundles(), TRUE);
  }

  /**
   * The nodes of one feed page, each listed once.
   *
   * @param string $entityTypeId
   *   The entity type.
   * @param string $bundle
   *   The bundle.
   * @param int $page
   *   The page, from 1.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects what the nodes depend on.
   *
   * @return array<int, array>
   *   The nodes, in entity order; a node already listed (by @id) is not
   *   repeated.
   */
  public function nodes(string $entityTypeId, string $bundle, int $page, CacheableMetadata $cacheability): array {
    $cacheability->addCacheableDependency($this->configFactory->get('neo_seo.settings'));
    $cacheability->addCacheTags([$entityTypeId . '_list']);
    $definition = $this->entityTypeManager->getDefinition($entityTypeId);
    $ids = $this->query($entityTypeId, $bundle)
      ->sort($definition->getKey('id'))
      ->range(($page - 1) * $this->perPage, $this->perPage)
      ->execute();
    $storage = $this->entityTypeManager->getStorage($entityTypeId);
    $nodes = [];
    foreach ($storage->loadMultiple($ids) as $entity) {
      if (!$entity instanceof ContentEntityInterface) {
        continue;
      }
      foreach ($this->entityNodes($entity, $cacheability) as $node) {
        $id = $node['@id'] ?? NULL;
        if ($id === NULL) {
          $nodes[] = $node;
        }
        elseif (!isset($nodes[$id])) {
          $nodes[$id] = $node;
        }
      }
    }
    $storage->resetCache($ids);
    return array_values($nodes);
  }

  /**
   * One entity's nodes, as its page's graph has them, cached.
   *
   * None when an anonymous visitor may not see the page, or it is kept out
   * of search results.
   *
   * @return array<int, array>
   *   The nodes.
   */
  public function entityNodes(ContentEntityInterface $entity, CacheableMetadata $cacheability): array {
    $cid = implode(':', [
      'neo_seo',
      'schemamap',
      $entity->getEntityTypeId(),
      $entity->id(),
      $entity->language()->getId(),
    ]);
    if ($cached = $this->cache->get($cid)) {
      $cacheability->addCacheTags($cached->tags);
      return $cached->data;
    }
    $built = new CacheableMetadata();
    $built->addCacheableDependency($entity);
    $this->accountSwitcher->switchTo(new AnonymousUserSession());
    try {
      $nodes = $this->renderer->executeInRenderContext(new RenderContext(), fn() => $this->build($entity, $built));
    }
    finally {
      $this->accountSwitcher->switchBack();
    }
    $cacheability->addCacheableDependency($built);
    if ($built->getCacheMaxAge() !== 0) {
      $expire = $built->getCacheMaxAge() === Cache::PERMANENT ? Cache::PERMANENT : time() + $built->getCacheMaxAge();
      $this->cache->set($cid, $nodes, $expire, $built->getCacheTags());
    }
    return $nodes;
  }

  /**
   * Builds an entity's nodes, as an anonymous visitor.
   */
  protected function build(ContentEntityInterface $entity, CacheableMetadata $built): array {
    $access = $entity->access('view', NULL, TRUE);
    $built->addCacheableDependency($access);
    if (!$access->isAllowed() || !$entity->hasLinkTemplate('canonical')) {
      return [];
    }
    $context = $this->contexts->forEntity($entity);
    $robots = $this->headValues->resolve($context)->get('robots');
    if (str_contains(strtolower($robots), 'noindex')) {
      $built->addCacheableDependency($context->getCacheability());
      return [];
    }
    $graph = $this->graphBuilder->build($context);
    $built->addCacheableDependency($context->getCacheability());
    return $graph['@graph'];
  }

  /**
   * The bundles with per-page SEO whose entities have pages.
   *
   * @return array<int, array{0: string, 1: string}>
   *   Entity type id and bundle pairs.
   */
  protected function bundles(): array {
    $bundles = [];
    foreach ((array) $this->configFactory->get('neo_seo.settings')->get('override_entity_types') as $entityTypeId) {
      $definition = $this->entityTypeManager->getDefinition((string) $entityTypeId, FALSE);
      if (!$definition instanceof ContentEntityTypeInterface || !$definition->hasLinkTemplate('canonical')) {
        continue;
      }
      foreach (array_keys($this->bundleInfo->getBundleInfo((string) $entityTypeId)) as $bundle) {
        $bundles[] = [(string) $entityTypeId, (string) $bundle];
      }
    }
    return $bundles;
  }

  /**
   * Whether a bundle's pages are kept out of search results by default.
   *
   * The robots template the head would use, the most specific set first:
   * the bundle's, the entity type's, the site's. A page may still be kept
   * out on its own; that is found as its graph is built.
   */
  protected function bundleNoindex(string $entityTypeId, string $bundle, CacheableMetadata $cacheability): bool {
    if (!$this->entityTypeManager->hasDefinition('neo_seo_defaults')) {
      return FALSE;
    }
    $storage = $this->entityTypeManager->getStorage('neo_seo_defaults');
    $cacheability->addCacheTags(['config:neo_seo_defaults_list']);
    foreach ([$entityTypeId . '__' . $bundle, $entityTypeId, 'global'] as $id) {
      $defaults = $storage->load($id);
      if (!$defaults) {
        continue;
      }
      $cacheability->addCacheableDependency($defaults);
      $robots = trim((string) (($defaults->get('values') ?? [])['robots'] ?? ''));
      if ($robots !== '') {
        return str_contains(strtolower($robots), 'noindex');
      }
    }
    return FALSE;
  }

  /**
   * A bundle's published entities, as a query.
   */
  protected function query(string $entityTypeId, string $bundle) {
    $definition = $this->entityTypeManager->getDefinition($entityTypeId);
    $query = $this->entityTypeManager->getStorage($entityTypeId)->getQuery()->accessCheck(FALSE);
    if ($definition->hasKey('bundle')) {
      $query->condition($definition->getKey('bundle'), $bundle);
    }
    if ($definition->hasKey('published')) {
      $query->condition($definition->getKey('published'), 1);
    }
    return $query;
  }

  /**
   * When a bundle's entities last changed, where they record it.
   */
  protected function lastChanged(string $entityTypeId, string $bundle): ?int {
    $definition = $this->entityTypeManager->getDefinition($entityTypeId);
    if (!$definition->entityClassImplements(EntityChangedInterface::class)) {
      return NULL;
    }
    $ids = $this->query($entityTypeId, $bundle)->sort('changed', 'DESC')->range(0, 1)->execute();
    $entity = $ids ? $this->entityTypeManager->getStorage($entityTypeId)->load(reset($ids)) : NULL;
    return $entity instanceof EntityChangedInterface ? (int) $entity->getChangedTime() : NULL;
  }

}
