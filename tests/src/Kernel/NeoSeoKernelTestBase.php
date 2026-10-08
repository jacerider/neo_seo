<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_seo\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_seo\Graph\GraphBuilder;
use Drupal\neo_seo\Graph\PageContext;
use Drupal\Tests\neo_seo\Traits\GraphAssertionsTrait;

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
    $this->installConfig(['neo_seo']);
    $this->config('system.site')->set('name', 'Westwood')->save();
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
   * Drops services that hold settings read earlier in the test.
   *
   * The settings repository reads its config once, when it is constructed, so
   * a test that rewrites the config mid-test needs a fresh one.
   */
  protected function resetSettings(): void {
    foreach (['neo_seo.settings', 'neo_seo.site_identity', 'Drupal\neo_seo\SiteIdentity\SiteIdentityResolver'] as $id) {
      $this->container->set($id, NULL);
    }
  }

}
