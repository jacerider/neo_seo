<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_seo\Kernel;

use Drupal\Component\Serialization\Json;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\neo_seo\Entity\SeoDefaults;
use Drupal\neo_seo\SeoOverrides;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The site's schema at /schemamap.xml, and the feeds it lists.
 *
 * @see \Drupal\neo_seo\Schemamap\SchemamapBuilder
 */
#[Group('neo_seo')]
class SchemamapTest extends NeoSeoKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node', 'text', 'filter', 'taxonomy'];

  /**
   * {@inheritdoc}
   *
   * Two entities a feed page, so paging shows with a few.
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->setParameter('neo_seo.schemamap.per_page', 2);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('system', ['sequences']);
    $this->installConfig(['node', 'filter']);
    $this->config('neo_seo.settings')
      ->set('override_entity_types', ['node', 'taxonomy_term'])
      ->set('share_image_style', '')
      ->save();
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    NodeType::create(['type' => 'empty', 'name' => 'Empty'])->save();
    Vocabulary::create(['vid' => 'tags', 'name' => 'Tags'])->save();
    Role::create(['id' => RoleInterface::ANONYMOUS_ID, 'label' => 'Anonymous'])
      ->grantPermission('access content')
      ->save();
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * One entry per feed page of each bundle with published, indexable pages.
   */
  public function testSchemamap(): void {
    foreach (['One', 'Two', 'Three'] as $title) {
      $this->page($title);
    }
    $this->page('Draft', FALSE);
    // A vocabulary kept out of search results lists nothing.
    SeoDefaults::create(['id' => 'taxonomy_term__tags', 'label' => 'Tags', 'values' => ['robots' => 'noindex, follow']])->save();
    Term::create(['vid' => 'tags', 'name' => 'A tag'])->save();

    $response = $this->get('/schemamap.xml');
    $this->assertSame(200, $response->getStatusCode());
    $this->assertStringStartsWith('application/xml', $response->headers->get('Content-Type'));
    $xml = (string) $response->getContent();
    $this->assertStringContainsString('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', $xml);
    preg_match_all('#<url contentType="structuredData/schema.org"><loc>([^<]+)</loc><lastmod>\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ</lastmod>#', $xml, $matches);
    $this->assertSame([
      'http://localhost/neo-seo/schema/node/page',
      'http://localhost/neo-seo/schema/node/page/2',
    ], $matches[1], 'Three published pages, two a feed page; no feed for an empty bundle or a noindex one.');
  }

  /**
   * A feed: each node once, a line each, as an anonymous visitor sees it.
   */
  public function testFeed(): void {
    $one = $this->page('One');
    $hidden = $this->page('Hidden', TRUE, ['noindex' => TRUE]);
    $three = $this->page('Three');

    $first = $this->feed('/neo-seo/schema/node/page');
    $ids = array_column($first, '@id');
    $this->assertContains($one->toUrl()->setAbsolute()->toString(), $ids, 'A page\'s WebPage.');
    $this->assertNotContains($hidden->toUrl()->setAbsolute()->toString(), $ids, 'A noindex page is left out.');
    $this->assertCount(1, array_keys($ids, 'http://localhost/#organization'), 'The site identity once, however many pages share it.');
    $this->assertCount(count($ids), array_unique($ids));
    foreach ($first as $node) {
      $this->assertSame('https://schema.org', $node['@context']);
    }

    $second = array_column($this->feed('/neo-seo/schema/node/page/2'), '@id');
    $this->assertContains($three->toUrl()->setAbsolute()->toString(), $second);

    // Built as an anonymous visitor: one they may not see is left out.
    Role::load(RoleInterface::ANONYMOUS_ID)->revokePermission('access content')->save();
    $this->container->get('cache.default')->deleteAll();
    $this->assertSame([], $this->feed('/neo-seo/schema/node/page/2'));
  }

  /**
   * A feed built at one address is not served at another.
   *
   * The @ids are absolute: warmed from Drush on http://, they must not reach
   * a visitor on https://.
   */
  public function testFeedPerAddress(): void {
    $one = $this->page('One');
    $this->assertContains('http://localhost/node/' . $one->id(), array_column($this->feed('/neo-seo/schema/node/page'), '@id'));
    $secure = array_column($this->feed('https://localhost/neo-seo/schema/node/page'), '@id');
    $this->assertContains('https://localhost/node/' . $one->id(), $secure);
    $this->assertContains('https://localhost/#organization', $secure);
    $this->assertEmpty(preg_grep('#^http://#', $secure), 'Nothing built for http:// on https://.');
  }

  /**
   * Unknown feeds are not found; the setting turns the whole thing off.
   */
  public function testNotFound(): void {
    $this->page('One');
    $this->assertSame(404, $this->get('/neo-seo/schema/node/nope')->getStatusCode());
    $this->assertSame(404, $this->get('/neo-seo/schema/user/user')->getStatusCode(), 'Only entity types with per-page SEO.');
    $this->config('neo_seo.settings')->set('schemamap', FALSE)->save();
    $this->assertSame(404, $this->get('/schemamap.xml')->getStatusCode());
    $this->assertSame(404, $this->get('/neo-seo/schema/node/page')->getStatusCode());
  }

  /**
   * The robots.txt line, for the robotstxt module to print.
   */
  public function testRobotsTxt(): void {
    $lines = $this->container->get('module_handler')->invokeAll('robotstxt');
    $this->assertSame(['Sitemap: http://localhost/schemamap.xml'], $lines);
    $this->config('neo_seo.settings')->set('schemamap', FALSE)->save();
    $this->assertSame([], $this->container->get('module_handler')->invokeAll('robotstxt'));
  }

  /**
   * Creates a page.
   */
  protected function page(string $title, bool $published = TRUE, array $overrides = []): NodeInterface {
    $node = Node::create([
      'type' => 'page',
      'title' => $title,
      'status' => $published,
      'uid' => 0,
      SeoOverrides::FIELD => $overrides,
    ]);
    $node->save();
    return $node;
  }

  /**
   * A feed's nodes.
   */
  protected function feed(string $path): array {
    $response = $this->get($path);
    $this->assertSame(200, $response->getStatusCode(), $path);
    $lines = array_filter(explode("\n", (string) $response->getContent()));
    return array_values(array_map(fn(string $line) => Json::decode($line), $lines));
  }

  /**
   * A GET request through the kernel.
   */
  protected function get(string $path): Response {
    return $this->container->get('http_kernel')->handle(Request::create($path));
  }

}
