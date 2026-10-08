<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_seo\Kernel;

use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_seo\Graph\GraphBuilder;
use Drupal\neo_seo\Graph\PageContext;
use Drupal\Tests\neo_seo\Traits\GraphAssertionsTrait;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Base for neo_seo kernel tests: the module set, a page and its graph.
 */
abstract class NeoSeoKernelTestBase extends KernelTestBase {

  use GraphAssertionsTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'image',
    'media',
    'token',
    'neo_settings',
    'neo_seo',
    'neo_seo_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['system', 'neo_seo']);
    $this->config('system.site')->set('name', 'Acme')->save();
  }

  /**
   * A page context built by hand.
   */
  protected function context(bool $front = FALSE): PageContext {
    return new PageContext(
      siteUrl: 'https://example.com/',
      canonicalUrl: $front ? 'https://example.com/' : 'https://example.com/about',
      langcode: 'en',
      siteName: 'Acme',
      isFront: $front,
      title: $front ? 'Acme' : 'About us',
    );
  }

  /**
   * Builds the document for a context.
   */
  protected function build(PageContext $context): array {
    return $this->container->get(GraphBuilder::class)->build($context);
  }

  /**
   * Drops services that hold settings read earlier in the test.
   *
   * The settings repository reads its config once, when it is constructed, so
   * a test that rewrites the config mid-test needs a fresh one.
   */
  protected function resetSettings(): void {
    $ids = [
      'neo_seo.settings',
      'neo_seo.site_identity',
      'Drupal\neo_seo\SiteIdentity\SiteIdentityResolver',
      'neo_seo.head_gate',
      'Drupal\neo_seo\Head\HeadGate',
      'Drupal\neo_seo\Hook\NeoSeoHooks',
    ];
    foreach ($ids as $id) {
      $this->container->set($id, NULL);
    }
  }

  /**
   * Makes a route the current one.
   */
  protected function pushRoute(string $name, array $parameters, string $path): void {
    $route = $this->container->get('router.route_provider')->getRouteByName($name);
    $request = Request::create($path);
    // Routing puts the route's defaults on the request, as here.
    $request->attributes->add($route->getDefaults());
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
    // The path matcher remembers whether the request was the front page.
    $this->container->set('path.matcher', NULL);
    $this->container->set('neo_seo.page_context_factory', NULL);
    $this->container->set('Drupal\neo_seo\Graph\PageContextFactory', NULL);
    $this->container->set('Drupal\neo_seo\Hook\NeoSeoHooks', NULL);
  }

}
