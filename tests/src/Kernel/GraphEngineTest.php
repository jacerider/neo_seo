<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_seo\Kernel;

use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\InputBag;
use PHPUnit\Framework\ExpectationFailedException;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_seo\Graph\GraphBuilder;
use Drupal\neo_seo\Graph\PageContext;
use Drupal\neo_seo\Hook\NeoSeoHooks;
use Drupal\Tests\neo_seo\Traits\GraphAssertionsTrait;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;

/**
 * The graph engine: one connected @graph per page.
 */
#[Group('neo_seo')]
class GraphEngineTest extends KernelTestBase {

  use GraphAssertionsTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'entity_test',
    'neo_seo',
    'neo_seo_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    $this->config('system.site')->set('name', 'Westwood')->set('page.front', '/user/login')->save();
  }

  /**
   * A page context built by hand.
   */
  protected function context(bool $front = FALSE): PageContext {
    return new PageContext(
      siteUrl: 'https://example.com/',
      canonicalUrl: $front ? 'https://example.com/' : 'https://example.com/about',
      langcode: 'en',
      siteName: 'Westwood',
      isFront: $front,
      title: $front ? 'Westwood' : 'About us',
    );
  }

  /**
   * Builds the document for a context.
   */
  protected function build(PageContext $context): array {
    return $this->container->get(GraphBuilder::class)->build($context);
  }

  /**
   * The core pieces link up, with Yoast's ids.
   */
  public function testCorePiecesAreConnected(): void {
    $document = $this->build($this->context());

    $this->assertSame('https://schema.org', $document['@context']);
    $this->assertSame([
      'https://example.com/#organization',
      'https://example.com/#website',
      'https://example.com/about',
    ], $this->graphIds($document));
    $this->assertGraphReferencesResolve($document);

    $website = $this->assertGraphNode($document, 'https://example.com/#website');
    $this->assertSame(['@id' => 'https://example.com/#organization'], $website['publisher']);
    $this->assertArrayNotHasKey('potentialAction', $website, 'No SearchAction: Google retired the sitelinks search box.');

    $page = $this->assertGraphNode($document, 'https://example.com/about');
    $this->assertSame('WebPage', $page['@type']);
    $this->assertSame('About us', $page['name']);
    $this->assertSame(['@id' => 'https://example.com/#website'], $page['isPartOf']);
    $this->assertSame('en', $page['inLanguage']);
    $this->assertArrayNotHasKey('about', $page, 'Only the front page is about the site identity.');
  }

  /**
   * The front page is about the site identity.
   */
  public function testFrontPageIsAboutTheSiteIdentity(): void {
    $document = $this->build($this->context(TRUE));
    $page = $this->assertGraphNode($document, 'https://example.com/');
    $this->assertSame(['@id' => 'https://example.com/#organization'], $page['about']);
    $this->assertGraphReferencesResolve($document);
  }

  /**
   * Another module's piece joins the graph only when it is needed.
   */
  public function testPieceFromAnotherModule(): void {
    $this->assertNotContains('https://example.com/about#thing', $this->graphIds($this->build($this->context())));

    $this->container->get('state')->set('neo_seo_test.piece', TRUE);
    $context = $this->context();
    $document = $this->build($context);
    $thing = $this->assertGraphNode($document, 'https://example.com/about#thing');
    $this->assertGraphReferencesResolve($document);

    // Empty values are dropped, all the way down, and lists stay lists.
    $this->assertArrayNotHasKey('description', $thing);
    $this->assertSame(['one', 'two'], $thing['keywords']);

    // What a piece read rides out on the page.
    $this->assertContains('neo_seo_test_tag', $context->getCacheability()->getCacheTags());
  }

  /**
   * The alter hook sees the assembled graph last.
   */
  public function testAlterHook(): void {
    $this->container->get('state')->set('neo_seo_test.alter', TRUE);
    $website = $this->assertGraphNode($this->build($this->context()), 'https://example.com/#website');
    $this->assertSame('Altered', $website['alternateName']);
  }

  /**
   * A dangling reference fails the assertion every later ticket relies on.
   */
  public function testDanglingReferenceIsCaught(): void {
    $document = $this->build($this->context());
    $document['@graph'][] = ['@type' => 'Thing', '@id' => 'x', 'subjectOf' => ['@id' => 'https://example.com/#nowhere']];
    try {
      $this->assertGraphReferencesResolve($document);
    }
    catch (ExpectationFailedException $e) {
      $this->assertStringContainsString('#nowhere', $e->getComparisonFailure()?->getActualAsString() ?? $e->getMessage());
      return;
    }
    $this->fail('A dangling reference was not reported.');
  }

  /**
   * An entity's canonical page: the context finds it and the page carries it.
   */
  public function testEntityCanonicalPage(): void {
    $entity = EntityTest::create(['name' => 'Grid modernization']);
    $entity->save();

    $this->pushRoute('entity.entity_test.canonical', ['entity_test' => $entity], '/entity_test/' . $entity->id());
    $context = $this->container->get('neo_seo.page_context_factory')->current();

    $this->assertSame($entity->id(), $context->entity?->id());
    $this->assertSame('Grid modernization', $context->title);
    $this->assertStringEndsWith('/entity_test/' . $entity->id(), $context->canonicalUrl);
    $this->assertContains('entity_test:' . $entity->id(), $context->getCacheability()->getCacheTags());
    $this->assertContains('route', $context->getCacheability()->getCacheContexts());
  }

  /**
   * The page attachment is one script holding one graph, and skips admin.
   */
  public function testPageAttachment(): void {
    $this->pushRoute('user.login', [], '/user/login');
    $attachments = ['#attached' => []];
    $this->container->get(NeoSeoHooks::class)->pageAttachments($attachments);

    $head = $attachments['#attached']['html_head'] ?? [];
    $this->assertCount(1, $head);
    [$element, $key] = $head[0];
    $this->assertSame('neo_seo_graph', $key);
    $this->assertSame('application/ld+json', $element['#attributes']['type']);
    $document = json_decode((string) $element['#value'], TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertGraphReferencesResolve($document);
    $this->assertStringNotContainsString('</', (string) $element['#value'], 'No value can close the script element.');
    $this->assertContains('config:system.site', $attachments['#cache']['tags']);

    // An admin route gets nothing.
    $this->pushRoute('system.admin', [], '/admin');
    $attachments = ['#attached' => []];
    $this->container->get(NeoSeoHooks::class)->pageAttachments($attachments);
    $this->assertSame([], $attachments['#attached'], 'An admin route gets no graph.');
  }

  /**
   * Makes a route the current one.
   */
  protected function pushRoute(string $name, array $parameters, string $path): void {
    $route = $this->container->get('router.route_provider')->getRouteByName($name);
    $request = Request::create($path);
    $request->attributes->add($parameters);
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, $name);
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $route);
    $request->attributes->set('_raw_variables', new InputBag(array_map(
      static fn($value) => is_object($value) && method_exists($value, 'id') ? $value->id() : $value,
      $parameters,
    )));
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);
    $this->container->get('current_route_match')->resetRouteMatch();
  }

}
