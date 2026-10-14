<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_seo\Unit\ComponentSchema;

use Drupal\neo_seo\ComponentSchema\Expression;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The value syntax of a component's `seo` declaration.
 */
#[Group('neo_seo')]
class ExpressionTest extends UnitTestCase {

  /**
   * One placeholder and nothing else is a whole value; anything else is not.
   */
  public function testWhole(): void {
    $this->assertSame('office.title', Expression::whole('{{ office.title }}'));
    $this->assertSame('office.address|postal_address', Expression::whole(' {{office.address|postal_address}} '));
    $this->assertNull(Expression::whole('#office-{{ office.title|slug }}'));
    $this->assertNull(Expression::whole('{{ a }} {{ b }}'));
    $this->assertNull(Expression::whole('Place'));
  }

  /**
   * A path, then filters, each with an optional quoted argument.
   */
  public function testParse(): void {
    $this->assertSame([
      'path' => ['office', 'address'],
      'filters' => [['name' => 'postal_address', 'arg' => NULL]],
    ], Expression::parse('office.address|postal_address'));
    $this->assertSame([
      'path' => ['tags', '0'],
      'filters' => [['name' => 'join', 'arg' => ' / '], ['name' => 'slug', 'arg' => NULL]],
    ], Expression::parse("tags.0 | join(' / ') | slug"));
    $this->assertSame(', ', Expression::parse('tags|join(", ")')['filters'][0]['arg']);
  }

  /**
   * What is not a path with filters is an error, said plainly.
   */
  public function testParseRejects(): void {
    foreach (['', 'office.', 'office title', 'office|Upper', "tags|join(' / )"] as $bad) {
      try {
        Expression::parse($bad);
        $this->fail("`$bad` parsed.");
      }
      catch (\InvalidArgumentException $e) {
        $this->assertStringContainsString('is not a path with filters', $e->getMessage());
      }
    }
  }

  /**
   * An `@each` is a list, then a row's name; braces around the list allowed.
   */
  public function testEach(): void {
    $this->assertSame(['path' => ['locations'], 'name' => 'office'], Expression::each('locations as office'));
    $this->assertSame(['path' => ['office', 'features'], 'name' => 'feature'], Expression::each('{{ office.features }} as feature'));
    $this->expectException(\InvalidArgumentException::class);
    Expression::each('locations');
  }

  /**
   * An `@if` is one placeholder, optionally negated.
   */
  public function testCondition(): void {
    $this->assertSame(['expression' => 'office.headquarters', 'negate' => FALSE], Expression::condition('{{ office.headquarters }}'));
    $this->assertSame(['expression' => 'office.headquarters', 'negate' => TRUE], Expression::condition('not {{ office.headquarters }}'));
    $this->expectException(\InvalidArgumentException::class);
    Expression::condition('office.headquarters');
  }

}
