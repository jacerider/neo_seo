<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_seo\Unit\ComponentSchema;

use Drupal\Component\Transliteration\PhpTransliteration;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\neo_seo\ComponentSchema\Evaluator;
use Drupal\neo_seo\ComponentSchema\Filters;
use Drupal\neo_seo\Graph\PageContext;
use Drupal\neo_seo\Graph\SchemaIds;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * How a declaration becomes nodes.
 *
 * The filters that build URLs need Drupal's routing and are covered by the
 * kernel test; these are the semantics: values, rows, conditions, pruning.
 *
 * @see \Drupal\Tests\neo_seo\Kernel\ComponentSchemaTest
 */
#[Group('neo_seo')]
class EvaluatorTest extends UnitTestCase {

  /**
   * The props the declarations read.
   */
  protected array $props = [];

  /**
   * The props that were resolved, in order.
   */
  protected array $resolved = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->props = [
      'heading' => ['title' => 'Our team'],
      'body' => Markup::create('<p>Hello <strong>there</strong>,<br>friend &amp; colleague.</p>'),
      'people' => [
        ['name' => 'Ada Lovelace', 'born' => '1815-12-10', 'roles' => ['Mathematician', 'Writer'], 'lead' => TRUE],
        ['name' => 'Ada Lovelace', 'roles' => [], 'lead' => FALSE],
        ['name' => '', 'roles' => ['Ghost']],
      ],
      'unused' => 'never read',
    ];
  }

  /**
   * Literal and placeholder values; a whole placeholder keeps its type.
   */
  public function testValues(): void {
    $this->props['count'] = 3;
    $node = $this->one([
      '@type' => 'Thing',
      'name' => '{{ heading.title }}',
      'alternateName' => 'Team: {{ heading.title }}',
      'description' => '{{ body|text }}',
      'size' => '{{ count }}',
      'inLanguage' => '{{ page.language }}',
      'publisher' => '{{ graph.organization }}',
      'isPartOf' => '{{ graph.page }}',
      'provider' => '{{ site.name }}',
      'keywords' => 'literal',
    ]);
    $this->assertSame([
      '@type' => 'Thing',
      'name' => 'Our team',
      'alternateName' => 'Team: Our team',
      'description' => 'Hello there, friend & colleague.',
      'size' => 3,
      'inLanguage' => 'en',
      'publisher' => ['@id' => 'https://example.com/#organization'],
      'isPartOf' => ['@id' => 'https://example.com/team'],
      'provider' => 'Acme',
      'keywords' => 'literal',
    ], $node);
    $this->assertNotContains('unused', $this->resolved, 'Only the props read are resolved.');
  }

  /**
   * One node per row, rows by name, nested lists, conditions and @id.
   */
  public function testRows(): void {
    $built = $this->evaluate([
      [
        '@type' => 'Person',
        '@each' => 'people as person',
        '@id' => '#person-{{ person.name|slug }}',
        '@required' => ['name'],
        'name' => '{{ person.name }}',
        'birthDate' => '{{ person.born|date }}',
        'jobTitle' => '{{ person.roles|join(" / ") }}',
        'knowsAbout' => '{{ person.roles|first }}',
        'memberOf' => [
          '@type' => 'Organization',
          '@if' => '{{ person.lead }}',
          'name' => '{{ heading.title }}',
        ],
        'hasOccupation' => [
          '@each' => 'person.roles as role',
          '@type' => 'Occupation',
          'name' => '{{ role }}',
        ],
      ],
    ]);
    $this->assertSame([1, 2, 3], array_column($built, 'index'));
    $this->assertSame(['name'], $built[0]['required']);

    [$ada, $second, $ghost] = array_column($built, 'node');
    $this->assertSame('https://example.com/team#person-ada-lovelace', $ada['@id']);
    $this->assertSame('1815-12-10', $ada['birthDate'], 'A date without a time stays a date.');
    $this->assertSame('Mathematician / Writer', $ada['jobTitle']);
    $this->assertSame('Mathematician', $ada['knowsAbout']);
    $this->assertSame(['@type' => 'Organization', 'name' => 'Our team'], $ada['memberOf']);
    $this->assertSame([
      ['@type' => 'Occupation', 'name' => 'Mathematician'],
      ['@type' => 'Occupation', 'name' => 'Writer'],
    ], $ada['hasOccupation']);

    $this->assertArrayNotHasKey('memberOf', $second, '@if: not a lead.');
    $this->assertArrayNotHasKey('hasOccupation', $second, 'An empty list is pruned.');
    $this->assertArrayNotHasKey('birthDate', $second);

    $this->assertArrayNotHasKey('@id', $ghost, 'An @id whose placeholders are all empty is no @id.');
    $this->assertArrayNotHasKey('name', $ghost, 'The caller drops it: it requires a name.');
  }

  /**
   * A `not` negates; a nested node missing what it requires is left out.
   */
  public function testNegationAndNestedRequirements(): void {
    $built = $this->evaluate([
      [
        '@type' => 'Person',
        '@each' => 'people as person',
        '@if' => 'not {{ person.lead }}',
        'name' => '{{ person.name }}',
        'affiliation' => [
          '@type' => 'Organization',
          '@required' => ['url'],
          'name' => '{{ heading.title }}',
        ],
      ],
    ]);
    // The lead is left out by @if; the third row, nameless and without its
    // affiliation, is only a type, and pruned. Rows keep their numbers.
    $this->assertSame([2], array_column($built, 'index'));
    $this->assertArrayNotHasKey('affiliation', $built[0]['node']);
  }

  /**
   * The position numbers the rows each `@each` prints, nested or not.
   */
  public function testPositions(): void {
    // The nameless row first, so a row left out comes before the others.
    [$ada, $second, $ghost] = $this->props['people'];
    $this->props['people'] = [$ghost, $ada, $second];
    $built = $this->evaluate([
      [
        '@type' => 'Person',
        '@each' => 'people as person',
        '@if' => '{{ person.name }}',
        'name' => '{{ person.name }}',
        'position' => '{{ loop.position }}',
        'hasOccupation' => [
          '@each' => 'person.roles as role',
          '@type' => 'Occupation',
          'name' => '{{ role }}',
          'position' => '{{ loop.position }}',
        ],
        'identifier' => 'person-{{ loop.position }}',
      ],
      [
        '@type' => 'ItemList',
        'position' => '{{ loop.position }}',
        'itemListElement' => [
          [
            '@type' => 'ListItem',
            '@each' => 'people as person',
            '@required' => ['name'],
            'position' => '{{ loop.position }}',
            'name' => '{{ person.name }}',
          ],
        ],
      ],
    ]);
    $this->assertSame([2, 3, NULL], array_column($built, 'index'), 'Rows keep their own numbers.');
    [$first, $next, $list] = array_column($built, 'node');

    $this->assertSame(1, $first['position'], 'A left-out row takes no position; a whole placeholder stays a number.');
    $this->assertSame(2, $next['position']);
    $this->assertSame([
      ['@type' => 'Occupation', 'name' => 'Mathematician', 'position' => 1],
      ['@type' => 'Occupation', 'name' => 'Writer', 'position' => 2],
    ], $first['hasOccupation'], 'A nested repeat numbers its own rows.');
    $this->assertSame('person-1', $first['identifier'], 'After a nested repeat, the row is at its own position again.');
    $this->assertSame('person-2', $next['identifier']);

    $this->assertArrayNotHasKey('position', $list, 'Outside a repeat there is no position.');
    $this->assertSame([
      ['@type' => 'ListItem', 'position' => 1, 'name' => 'Ada Lovelace'],
      ['@type' => 'ListItem', 'position' => 2, 'name' => 'Ada Lovelace'],
    ], $list['itemListElement'], 'A nested row missing what it requires takes no position.');
  }

  /**
   * What reads nothing prints nothing, down to a node of only a type.
   */
  public function testPruning(): void {
    $empty = [
      '@type' => 'Thing',
      'name' => '{{ missing }}',
      'url' => '{{ heading.missing }}',
      'image' => ['@type' => 'ImageObject', 'url' => '{{ nope }}'],
    ];
    $this->assertSame([], $this->evaluate([$empty]));
    $list = ['@type' => 'Thing', '@each' => 'missing as row', 'name' => 'x'];
    $this->assertSame([], $this->evaluate([$list]), 'A missing list repeats nothing.');
    $filter = ['@type' => 'Thing', 'name' => '{{ heading|nofilter }}'];
    $this->assertSame([], $this->evaluate([$filter]), 'An unknown filter is no value.');
  }

  /**
   * The postal_address and slug filters.
   */
  public function testFilters(): void {
    $this->props['address'] = [
      'address_line1' => '1 Main St',
      'address_line2' => '',
      'locality' => 'Springfield',
      'country_code' => 'US',
    ];
    $this->props['title'] = 'Café — Déjà Vu!';
    $node = $this->one([
      '@type' => 'Place',
      'address' => '{{ address|postal_address }}',
      'identifier' => '{{ title|slug }}',
    ]);
    $this->assertSame([
      '@type' => 'PostalAddress',
      'streetAddress' => '1 Main St',
      'addressLocality' => 'Springfield',
      'addressCountry' => 'US',
    ], $node['address']);
    $this->assertSame('cafe-deja-vu', $node['identifier']);
  }

  /**
   * Evaluates declarations over the props.
   */
  protected function evaluate(array $declarations): array {
    $filters = new Filters(
      $this->createMock(FileUrlGeneratorInterface::class),
      $this->createMock(StreamWrapperManagerInterface::class),
      new PhpTransliteration(),
    );
    $context = new PageContext(
      siteUrl: 'https://example.com/',
      canonicalUrl: 'https://example.com/team',
      langcode: 'en',
      siteName: 'Acme',
      title: 'Team',
    );
    $prop = function (string $name): mixed {
      $this->resolved[] = $name;
      return $this->props[$name] ?? NULL;
    };
    return (new Evaluator($filters, new SchemaIds()))->evaluate($declarations, \Closure::fromCallable($prop), $context);
  }

  /**
   * The one node of one declaration.
   */
  protected function one(array $declaration): array {
    $built = $this->evaluate([$declaration]);
    $this->assertCount(1, $built);
    return $built[0]['node'];
  }

}
