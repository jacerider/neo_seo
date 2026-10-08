<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_seo\Unit;

use Drupal\neo_seo\TokenText;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tidying what token replacement leaves behind.
 */
#[Group('neo_seo')]
class TokenTextTest extends UnitTestCase {

  /**
   * Cases: input, tidied.
   */
  public static function cases(): array {
    return [
      'untouched' => ['Grid & growth | Westwood', 'Grid & growth | Westwood'],
      'leading pipe' => [' | Westwood', 'Westwood'],
      'trailing comma' => ['2805 Dallas Parkway, ', '2805 Dallas Parkway'],
      'doubled middle' => ['A |  | B', 'A | B'],
      'doubled comma' => ['a, , b', 'a, b'],
      'whitespace' => ["  a \n\t b  ", 'a b'],
      'hyphen inside a word stays' => ['Well-known - Westwood', 'Well-known - Westwood'],
      'only separators' => [' | , ', ''],
      'colon prefix' => ['Report: ', 'Report'],
    ];
  }

  /**
   * Tidies stranded separators.
   */
  #[DataProvider('cases')]
  public function testTidy(string $input, string $expected): void {
    $this->assertSame($expected, TokenText::tidy($input));
  }

}
