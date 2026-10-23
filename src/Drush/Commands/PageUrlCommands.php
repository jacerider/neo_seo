<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Drush\Commands;

use Drupal\neo_seo\PageUrl;
use Drupal\neo_seo\PageUrlPatterns;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Drush commands for page URLs.
 */
final class PageUrlCommands extends DrushCommands {

  use AutowireTrait;

  /**
   * How many moving pages are listed per pattern.
   */
  private const LISTED = 10;

  public function __construct(
    #[Autowire(service: 'neo_seo.page_url_patterns')]
    private readonly PageUrlPatterns $patterns,
  ) {
    parent::__construct();
  }

  /**
   * Moves URL alias patterns onto the slug tokens, so editors can set slugs.
   *
   * Shows what it would change in each enabled pathauto pattern, and which
   * pages' addresses that would move, then asks. A last segment that is the
   * label token ([node:title], [term:name]) becomes [neo:slug]; core's parents
   * path becomes [neo:parents:join-path]. Patterns it cannot change safely, or
   * whose content cannot hold a slug, are listed with the reason. Then it
   * lists the form displays that still show core's URL alias field where
   * neo_seo's URL field takes its place, and offers to disable it. It writes
   * active configuration only (export it) and never re-aliases a page.
   */
  #[CLI\Command(name: 'neo-seo:slugs')]
  #[CLI\Option(name: 'pattern', description: 'Only this pathauto pattern (its machine name), and the form displays of what it builds aliases for.')]
  #[CLI\Option(name: 'no-check', description: 'Skip building every page’s alias from the old pattern and the new.')]
  #[CLI\Usage(name: 'drush neo-seo:slugs', description: 'See what would change, then confirm.')]
  #[CLI\Usage(name: 'drush neo-seo:slugs --pattern=page -y', description: 'Swap one pattern without asking.')]
  public function slugs(array $options = ['pattern' => self::REQ, 'no-check' => FALSE]): int {
    if (!$this->patterns->available()) {
      $this->io()->error(dt('Pathauto is not installed: there are no URL alias patterns to change.'));
      return self::EXIT_FAILURE;
    }
    $only = $options['pattern'] ?: NULL;
    $plan = $this->patterns->plan($only, !$options['no-check']);
    if (!$plan) {
      $this->io()->warning($only ? dt('No enabled pattern is called @id.', ['@id' => $only]) : dt('No enabled URL alias patterns.'));
      return self::EXIT_SUCCESS;
    }
    $this->swapPatterns($plan, (bool) $options['no-check']);
    $this->disablePathWidgets($only);
    return self::EXIT_SUCCESS;
  }

  /**
   * Shows the plan for the patterns, and swaps them once confirmed.
   */
  private function swapPatterns(array $plan, bool $unchecked): void {
    $rows = [];
    foreach ($plan as $id => $row) {
      $note = match ($row['status']) {
        'swap' => $unchecked ? dt('not checked') : dt('@moving of @checked pages would move', [
          '@moving' => count($row['moving']),
          '@checked' => $row['checked'],
        ]),
        default => $row['reason'],
      };
      $rows[] = [$id, $row['from'], $row['status'] === 'swap' ? $row['to'] : '—', $note];
    }
    $this->io()->table([dt('Pattern'), dt('Now'), dt('Becomes'), dt('Note')], $rows);
    foreach ($plan as $id => $row) {
      if ($row['moving']) {
        $this->io()->text(dt('@id: these pages would move:', ['@id' => $id]));
        $this->io()->listing(array_slice($row['moving'], 0, self::LISTED));
        if (count($row['moving']) > self::LISTED) {
          $this->io()->text(dt('…and @more more.', ['@more' => count($row['moving']) - self::LISTED]));
        }
      }
    }

    $swaps = count(array_filter($plan, fn($row) => $row['status'] === 'swap'));
    if (!$swaps) {
      $this->io()->success(dt('No pattern to change.'));
      return;
    }
    if (!$this->io()->confirm(dt('Change @count pattern(s)?', ['@count' => $swaps]))) {
      return;
    }
    $changed = $this->patterns->apply($plan);
    $this->io()->success(dt('Changed @count pattern(s) to use @slug. Pages keep their addresses until saved; export the configuration to keep the change.', [
      '@count' => $changed,
      '@slug' => PageUrl::TOKEN,
    ]));
  }

  /**
   * Offers to hide core's URL alias field where neo_seo's URL field serves.
   *
   * After the patterns, so a bundle just moved onto the slug counts.
   */
  private function disablePathWidgets(?string $only): void {
    $displays = $this->patterns->pathWidgets($only);
    if (!$displays) {
      return;
    }
    $this->io()->text(dt('These form displays still show the URL alias field. neo_seo’s URL field takes its place there, so it does nothing:'));
    $this->io()->listing($displays);
    if (!$this->io()->confirm(dt('Disable the URL alias field in @count form display(s)?', ['@count' => count($displays)]))) {
      return;
    }
    $changed = $this->patterns->disablePathWidgets($displays);
    $this->io()->success(dt('Disabled the URL alias field in @count form display(s); export the configuration to keep the change.', ['@count' => $changed]));
  }

}
