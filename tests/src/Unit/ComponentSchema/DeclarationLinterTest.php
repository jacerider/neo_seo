<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_seo\Unit\ComponentSchema;

use Drupal\neo_seo\ComponentSchema\DeclarationLinter;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * The static checks of a component's `seo` declaration.
 */
#[Group('neo_seo')]
class DeclarationLinterTest extends UnitTestCase {

  /**
   * A definition's props, as the SDC plugin manager holds them.
   */
  protected const PROPS = [
    'title' => ['type' => 'string'],
    'locations' => [
      'type' => 'array',
      'items' => [
        'type' => 'object',
        'properties' => [
          'title' => ['type' => 'string'],
          'address' => ['type' => 'object', 'properties' => ['locality' => ['type' => 'string']]],
          'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
      ],
    ],
  ];

  /**
   * Each declaration, and the findings it gets.
   */
  #[DataProvider('cases')]
  public function testLint(mixed $seo, array $expected): void {
    $definition = ['props' => ['type' => 'object', 'properties' => static::PROPS], 'seo' => $seo];
    $this->assertSame($expected, array_map(
      fn(array $finding) => $finding['severity'] . ': ' . substr($finding['message'], strlen('SEO: ')),
      (new DeclarationLinter())->lint($definition),
    ));
  }

  /**
   * Declarations and findings.
   */
  public static function cases(): array {
    $office = ['@type' => 'Place', '@each' => 'locations as office'];
    return [
      'clean' => [[
        $office + [
          '@id' => '#office-{{ office.title|slug }}',
          '@required' => ['name'],
          'name' => '{{ office.title }}',
          'address' => ['@type' => 'PostalAddress', 'addressLocality' => '{{ office.address.locality }}'],
          'keywords' => [
            [
              '@each' => 'office.tags as tag',
              '@type' => 'DefinedTerm',
              'name' => '{{ tag }}',
              'position' => '{{ loop.position }}',
            ],
          ],
          'position' => '{{ loop.position }}',
          'url' => '{{ page.url }}',
          'containedInPlace' => '{{ graph.organization }}',
          'description' => '{{ title }} in {{ site.name }}',
        ],
      ], [],
      ],
      'not a list' => [['@type' => 'Place'], ['error: `seo` must be a list of nodes, each with an `@type`.']],
      'no type' => [[['name' => 'x']], ['error: `seo` node 1 has no `@type`.']],
      'unknown prop' => [[['@type' => 'Thing', 'name' => '{{ titel }}']], [
        'error: `seo` node 1 (Thing) `name` reads `titel`, but `titel` is not a prop of the component.',
      ],
      ],
      'unknown key in a row' => [[$office + ['name' => '{{ office.nmae }}']], [
        'warning: `seo` node 1 (Place) `name` reads `office.nmae`, but `office` declares no `nmae`.',
      ],
      ],
      'row out of scope' => [[['@type' => 'Thing', 'name' => '{{ office.title }}']], [
        'error: `seo` node 1 (Thing) `name` reads `office.title`, but `office` is not a prop of the component.',
      ],
      ],
      'each over a string' => [[['@type' => 'Thing', '@each' => 'title as t', 'name' => '{{ t }}']], [
        'warning: `seo` node 1 (Thing) `@each` repeats over `title`, which is not an array prop.',
      ],
      ],
      'each syntax' => [[['@type' => 'Thing', '@each' => 'locations']], [
        'error: `seo` node 1 (Thing): `@each: locations` is not `<list> as <name>`, e.g. `locations as office`.',
      ],
      ],
      'row named like a prop' => [[['@type' => 'Thing', '@each' => 'locations as title']], [
        'error: `seo` node 1 (Thing) `@each` names its rows `title`, which is already a prop or a reserved name (graph, loop, page, site).',
      ],
      ],
      'row named loop' => [[['@type' => 'Thing', '@each' => 'locations as loop']], [
        'error: `seo` node 1 (Thing) `@each` names its rows `loop`, which is already a prop or a reserved name (graph, loop, page, site).',
      ],
      ],
      'position outside a repeat' => [[['@type' => 'ItemList', 'position' => '{{ loop.position }}']], [
        'error: `seo` node 1 (ItemList) `position` reads `loop.position` outside an `@each`: there is no row to number.',
      ],
      ],
      'unknown filter' => [[['@type' => 'Thing', 'name' => '{{ title|upper }}']], [
        'error: `seo` node 1 (Thing) `name` uses an unknown filter `upper`. Filters: postal_address, url, image, text, date, slug, first, join.',
      ],
      ],
      'reserved roots' => [[$office + ['a' => '{{ graph.logo }}', 'b' => '{{ page }}', 'c' => '{{ loop.index }}']], [
        'error: `seo` node 1 (Place) `a` reads `graph.logo`; `graph` offers graph.organization, graph.website, graph.page.',
        'error: `seo` node 1 (Place) `b` reads `page`; `page` offers page.url, page.title, page.language.',
        'error: `seo` node 1 (Place) `c` reads `loop.index`; `loop` offers loop.position.',
      ],
      ],
      'bad if' => [[['@type' => 'Thing', '@if' => 'title', 'name' => 'x']], [
        'error: `seo` node 1 (Thing): `@if: title` is not `{{ path }}` or `not {{ path }}`.',
      ],
      ],
      'required not declared' => [[['@type' => 'Thing', '@required' => ['url'], 'name' => 'x']], [
        'warning: `seo` node 1 (Thing) requires `url`, which it does not declare: it would never be printed.',
      ],
      ],
      'unknown directive' => [[['@type' => 'Thing', '@when' => 'x']], [
        'error: `seo` node 1 (Thing) has an unknown directive `@when`. Directives: @each, @if, @required; @type and @id are printed.',
      ],
      ],
      'bad syntax' => [[['@type' => 'Thing', 'name' => '{{ title| }}']], [
        'error: `seo` node 1 (Thing) `name`: `{{ title| }}` is not a path with filters, e.g. `{{ office.address|postal_address }}`.',
      ],
      ],
    ];
  }

  /**
   * A definition without `seo` is not this linter's.
   */
  public function testNoDeclaration(): void {
    $this->assertSame([], (new DeclarationLinter())->lint(['props' => []]));
  }

}
