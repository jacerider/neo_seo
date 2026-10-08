<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Migration;

use Drupal\Component\Utility\Crypt;
use Drupal\neo_seo\Validation\StructuredDataValidator;

/**
 * Compares what pages print in their head today with what neo_seo will print.
 *
 * Each page is fetched twice over HTTP: as visitors see it (metatag's head),
 * and in diff mode (neo_seo's head, metatag's stripped). The head tags and
 * the JSON-LD nodes of the two are compared.
 */
class HeadDiff {

  /**
   * The class neo_seo's own JSON-LD script carries.
   */
  protected const GRAPH_CLASS = 'neo-seo-graph';

  /**
   * Meta tags that belong to the theme, not to SEO.
   */
  protected const IGNORED_META = [
    'viewport',
    'mobileoptimized',
    'handheldfriendly',
    'apple-mobile-web-app-title',
    'theme-color',
    'msapplication-tilecolor',
    'msapplication-config',
  ];

  /**
   * Constructs the diff.
   *
   * @param \Drupal\neo_seo\Validation\StructuredDataValidator $fetcher
   *   The page fetcher.
   * @param \Drupal\neo_seo\Migration\DiffMode $diffMode
   *   The diff mode.
   */
  public function __construct(
    protected StructuredDataValidator $fetcher,
    protected DiffMode $diffMode,
  ) {}

  /**
   * Fetches the pages both ways and compares them.
   *
   * @param array<string, string> $paths
   *   Label => site-relative path.
   * @param bool $verify
   *   Whether to verify TLS certificates.
   *
   * @return array<string, array{url: string, head: array<string, array{0: ?string, 1: ?string}>, graph: array<string, array{0: ?string, 1: ?string}>}>
   *   Label => differences: what is printed today (0) and after the switch (1).
   */
  public function run(array $paths, bool $verify = TRUE): array {
    $before = $this->fetcher->fetch($paths, $verify);
    $token = $this->diffMode->start();
    try {
      // A query string of its own keeps the page cache from answering.
      $buster = ['neo_seo_diff' => substr(Crypt::hashBase64($token), 0, 8)];
      $after = $this->fetcher->fetch($paths, $verify, [DiffMode::HEADER => $token], $buster);
    }
    finally {
      $this->diffMode->stop();
    }
    $diffs = [];
    foreach ($paths as $label => $path) {
      $old = $before[$label]['html'] ?? '';
      $new = $after[$label]['html'] ?? '';
      $diffs[$label] = [
        'url' => $before[$label]['url'] ?? $path,
        'status' => [$before[$label]['status'] ?? 0, $after[$label]['status'] ?? 0],
        'head' => $this->compare(static::headTags($old), static::headTags($new)),
        'graph' => $this->compare(static::graphNodes($old, FALSE), static::graphNodes($new, TRUE)),
      ];
    }
    return $diffs;
  }

  /**
   * The head tags of a page: <title>, meta by name or property, canonical.
   *
   * @return array<string, string>
   *   Tag => content; repeated tags are joined with " · ".
   */
  public static function headTags(string $html): array {
    $head = strstr($html, '</head>', TRUE) ?: $html;
    $tags = [];
    if (preg_match('#<title[^>]*>(.*?)</title>#si', $head, $match)) {
      $tags['<title>'] = static::text($match[1]);
    }
    foreach (static::elements($head, 'meta') as $attributes) {
      $name = $attributes['name'] ?? $attributes['property'] ?? NULL;
      if ($name === NULL || !isset($attributes['content']) || in_array(strtolower($name), static::IGNORED_META, TRUE)) {
        continue;
      }
      $value = static::text($attributes['content']);
      $tags[$name] = isset($tags[$name]) ? $tags[$name] . ' · ' . $value : $value;
    }
    foreach (static::elements($head, 'link') as $attributes) {
      if (in_array($attributes['rel'] ?? '', ['canonical', 'shortlink'], TRUE)) {
        $tags['link:' . $attributes['rel']] = $attributes['href'] ?? '';
      }
    }
    ksort($tags);
    return $tags;
  }

  /**
   * The JSON-LD nodes of a page, as "Type @id" => property summary.
   *
   * @param string $html
   *   The page.
   * @param bool $neoSeo
   *   TRUE for neo_seo's script only, FALSE for everything else.
   *
   * @return array<string, string>
   *   Node => its properties and scalar values, one line.
   */
  public static function graphNodes(string $html, bool $neoSeo): array {
    $nodes = [];
    preg_match_all('#<script([^>]*type=["\']application/ld\+json["\'][^>]*)>(.*?)</script>#si', $html, $scripts, PREG_SET_ORDER);
    foreach ($scripts as [, $attributes, $body]) {
      if (str_contains($attributes, static::GRAPH_CLASS) !== $neoSeo) {
        continue;
      }
      $json = json_decode($body, TRUE);
      if (!is_array($json)) {
        continue;
      }
      foreach ($json['@graph'] ?? [$json] as $node) {
        $type = implode('+', (array) ($node['@type'] ?? '?'));
        $key = $type . ' ' . ($node['@id'] ?? '(no @id)');
        unset($node['@type'], $node['@id'], $node['@context']);
        ksort($node);
        $parts = [];
        foreach ($node as $property => $value) {
          $parts[] = $property . '=' . static::summarize($value);
        }
        $nodes[$key] = implode('; ', $parts);
      }
    }
    ksort($nodes);
    return $nodes;
  }

  /**
   * Writes a Markdown report of diffs.
   *
   * @return string
   *   The report.
   */
  public static function report(array $diffs, array $plan = []): string {
    $lines = [
      '# neo_seo migration diff',
      '',
      'Generated ' . date('Y-m-d H:i T') . '. "Today" is what visitors see (metatag); "After" is what neo_seo prints once metatag is gone.',
      '',
    ];
    if ($plan) {
      $lines[] = '## Conversion';
      $lines[] = '';
      $counts = array_count_values(array_column($plan['report'], 'outcome'));
      ksort($counts);
      $lines[] = implode(' · ', array_map(static fn($outcome, $count) => "$outcome: $count", array_keys($counts), $counts));
      $lines[] = '';
      $lines[] = '| Set | Tag | Outcome | Note |';
      $lines[] = '|---|---|---|---|';
      foreach ($plan['report'] as $line) {
        if ($line['outcome'] !== 'derived') {
          $lines[] = sprintf('| %s | `%s` | %s | %s |', $line['set'], $line['tag'], $line['outcome'], str_replace('|', '\|', $line['note']));
        }
      }
      $lines[] = '';
    }
    foreach ($diffs as $label => $diff) {
      $lines[] = "## $label";
      $lines[] = '';
      $lines[] = $diff['url'] . ' — HTTP ' . implode(' → ', $diff['status']);
      $lines[] = '';
      foreach (['head' => 'Head', 'graph' => 'Graph'] as $part => $title) {
        if (!$diff[$part]) {
          $lines[] = "$title: no differences.";
          $lines[] = '';
          continue;
        }
        $lines[] = "| $title | Today | After |";
        $lines[] = '|---|---|---|';
        foreach ($diff[$part] as $key => [$old, $new]) {
          $lines[] = sprintf('| `%s` | %s | %s |', str_replace('|', '\|', $key), static::cell($old), static::cell($new));
        }
        $lines[] = '';
      }
    }
    return implode("\n", $lines) . "\n";
  }

  /**
   * The keys whose values differ, with both values.
   */
  protected function compare(array $old, array $new): array {
    $diff = [];
    foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $key) {
      if (($old[$key] ?? NULL) !== ($new[$key] ?? NULL)) {
        $diff[$key] = [$old[$key] ?? NULL, $new[$key] ?? NULL];
      }
    }
    ksort($diff);
    return $diff;
  }

  /**
   * The attributes of every <tag …> in some HTML.
   *
   * @return array<int, array<string, string>>
   *   One attribute map per element.
   */
  protected static function elements(string $html, string $tag): array {
    preg_match_all('#<' . $tag . '\s([^>]*)>#i', $html, $matches);
    $elements = [];
    foreach ($matches[1] as $attributes) {
      preg_match_all('#([a-z:_-]+)\s*=\s*("([^"]*)"|\'([^\']*)\')#i', $attributes, $pairs, PREG_SET_ORDER);
      $element = [];
      foreach ($pairs as $pair) {
        $element[strtolower($pair[1])] = $pair[4] ?? $pair[3];
      }
      $elements[] = $element;
    }
    return $elements;
  }

  /**
   * A property value in a few characters: scalars cut, references as →id.
   */
  protected static function summarize(mixed $value): string {
    if (is_scalar($value)) {
      return mb_strimwidth((string) $value, 0, 60, '…');
    }
    if (is_array($value) && isset($value['@id']) && count($value) === 1) {
      return '→' . $value['@id'];
    }
    return '{…}';
  }

  /**
   * Decoded, single-line text.
   */
  protected static function text(string $value): string {
    return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($value, ENT_QUOTES | ENT_HTML5)));
  }

  /**
   * A Markdown table cell.
   */
  protected static function cell(?string $value): string {
    return $value === NULL ? '—' : str_replace('|', '\|', mb_strimwidth($value, 0, 140, '…'));
  }

}
