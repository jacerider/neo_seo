<?php

declare(strict_types=1);

namespace Drupal\neo_seo;

/**
 * Tidies text produced by token replacement.
 */
final class TokenText {

  /**
   * Tidies the separators a token that resolved to nothing leaves behind.
   *
   * "[node:title] | [site:name]" with no node is " | Westwood"; a street with
   * no second line is "2805 Dallas Parkway, ". The separator is stranded at an
   * end, or doubled in the middle, and goes. Whitespace collapses to single
   * spaces.
   *
   * @param string $text
   *   The replaced text.
   *
   * @return string
   *   The tidied text.
   */
  public static function tidy(string $text): string {
    $text = trim((string) preg_replace('/\s+/u', ' ', $text));
    // A separator run in the middle ("a | | b", "a, , b") collapses to its
    // first separator.
    $text = (string) preg_replace('/([|,·–—-])(?:\s*[|,·–—-])+/u', '$1', $text);
    // A separator stranded at either end goes, with its space.
    $text = (string) preg_replace('/^(?:\s*[|,·:–—-]\s*)+|(?:\s*[|,·:–—-]\s*)+$/u', '', $text);
    return trim($text);
  }

}
