<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_seo\Kernel;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Render\RenderContext;
use Drupal\Tests\neo_alchemist\Kernel\HybridFieldKernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * A component's `seo` declaration: its nodes, its audit, its lint.
 *
 * The fixture, seo_places, repeats a node over a list with a nested list in
 * each row, reads an address, a link and the page, keeps a point only where
 * there are coordinates, takes its @id from a name two rows share, and has a
 * row without the name it requires.
 *
 * @see \Drupal\neo_seo\ComponentSchema\Evaluator
 */
#[Group('neo_seo')]
class ComponentSchemaTest extends HybridFieldKernelTestBase {

  /**
   * {@inheritdoc}
   *
   * The fixture's url and address props are backed by link and address.
   */
  protected static $modules = [
    'link',
    'address',
    'token',
    'neo_seo',
    'neo_seo_alchemist_test',
  ];

  /**
   * The fixture.
   */
  protected const COMPONENT = 'neo_seo_alchemist_test:seo_places';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'neo_seo']);
    $this->config('system.site')->set('name', 'Acme')->save();
  }

  /**
   * The nodes a page placing the component would get.
   */
  public function testPreview(): void {
    $result = $this->preview();
    $ids = array_column($result['nodes'], '@id');
    $base = $this->container->get('neo_seo.page_context_factory')->example()->canonicalUrl;
    $this->assertSame([$base . '#place-main-office', $base . '#place-main-office-2'], $ids, 'One per row; a shared @id takes a number.');

    $main = $result['nodes'][0];
    $this->assertSame('Place', $main['@type']);
    $this->assertSame('Main Office', $main['name']);
    $this->assertSame('Main Office — one of Our places', $main['description'], 'Placeholders in text are filled in.');
    $this->assertSame([
      '@type' => 'PostalAddress',
      'streetAddress' => '1 Main St, Suite 2',
      'addressLocality' => 'Springfield',
      'addressRegion' => 'OR',
      'postalCode' => '97477',
      'addressCountry' => 'US',
    ], $main['address']);
    $this->assertSame('https://maps.example.com/main', $main['hasMap']);
    $this->assertSame(['@type' => 'GeoCoordinates', 'latitude' => 44.05, 'longitude' => -123.02], $main['geo'], 'A whole placeholder keeps its type.');
    $this->assertSame([
      ['@type' => 'LocationFeatureSpecification', 'name' => 'Parking', 'value' => TRUE],
      ['@type' => 'LocationFeatureSpecification', 'name' => 'Step-free access', 'value' => TRUE],
    ], $main['amenityFeature'], 'A nested @each is a list.');
    $this->assertStringEndsWith('#organization', $main['containedInPlace']['@id']);
    $this->assertSame($base, $main['url']);

    $side = $result['nodes'][1];
    $this->assertArrayNotHasKey('geo', $side, '@if leaves out the point without coordinates.');
    $this->assertArrayNotHasKey('amenityFeature', $side, 'An empty list is pruned.');

    $this->assertCount(1, $result['dropped']);
    $this->assertSame(3, $result['dropped'][0]['index']);
    $this->assertSame(['name'], $result['dropped'][0]['missing'], 'A row without its required name is left out.');
  }

  /**
   * The audit reports what is left out, and shows the JSON-LD.
   */
  public function testAudit(): void {
    $result = $this->container->get('neo_alchemist.component_audit')->run(static::COMPONENT);
    $schema = array_values(array_filter($result['issues'], fn(array $issue) => $issue['check'] === 'schema'));
    $this->assertCount(1, $schema, 'Once: the declaration is read on the examples render only.');
    $this->assertSame('The Place of row 3 would be left out of the page’s schema: it has no name.', $schema[0]['message']);
    $this->assertSame('Schema (JSON-LD)', $result['reports'][0]['label']);
    $document = Json::decode($result['reports'][0]['content']);
    $this->assertSame('https://schema.org', $document['@context']);
    $this->assertCount(2, $document['@graph']);
  }

  /**
   * The declaration is linted with the component; this one is clean.
   */
  public function testLint(): void {
    $definition = $this->container->get('plugin.manager.sdc')->getDefinition(static::COMPONENT);
    $this->assertArrayHasKey('seo', $definition, 'A top-level key reaches the definition.');
    $this->assertSame([[], []], $this->container->get('neo_alchemist.component_linters')->lint(static::COMPONENT, $definition));

    $definition['seo'][0]['name'] = '{{ place.nmae }}';
    [$errors, $warnings] = $this->container->get('neo_alchemist.component_linters')->lint(static::COMPONENT, $definition);
    $this->assertSame([], $errors);
    $this->assertSame(['SEO: `seo` node 1 (Place) `name` reads `place.nmae`, but `place` declares no `nmae`.'], $warnings);
  }

  /**
   * The fixture's nodes, from its examples, on the example page.
   */
  protected function preview(): array {
    $component = $this->container->get('neo_alchemist.preview_builder')->build(static::COMPONENT);
    $this->assertNotNull($component);
    $context = $this->container->get('neo_seo.page_context_factory')->example();
    return $this->container->get('renderer')->executeInRenderContext(
      new RenderContext(),
      fn() => $this->container->get('neo_seo.component_pieces')->preview($component, $context),
    );
  }

}
