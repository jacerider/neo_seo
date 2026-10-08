<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Drush\Commands;

use Consolidation\AnnotatedCommand\CommandResult;
use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\neo_seo\Migration\HeadDiff;
use Drupal\neo_seo\Migration\MetatagMigrator;
use Drupal\neo_seo\Validation\StructuredDataValidator;
use Drupal\neo_seo\Validation\ValidationSampler;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Drush commands for neo_seo.
 */
final class NeoSeoCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    #[Autowire(service: 'neo_seo.validation_sampler')]
    private readonly ValidationSampler $sampler,
    #[Autowire(service: 'neo_seo.structured_data_validator')]
    private readonly StructuredDataValidator $validator,
    #[Autowire(service: 'neo_seo.metatag_migrator')]
    private readonly MetatagMigrator $migrator,
    #[Autowire(service: 'neo_seo.head_diff')]
    private readonly HeadDiff $headDiff,
    #[Autowire(service: 'config.factory')]
    private readonly ConfigFactoryInterface $configFactory,
    #[Autowire(service: 'file_system')]
    private readonly FileSystemInterface $fileSystem,
  ) {
    parent::__construct();
  }

  /**
   * Converts the site's metatag setup into neo_seo's and diffs the result.
   *
   * Converts metatag defaults into default templates, schema_metatag into
   * bundle mappings and site identity, and metatag field values into SEO
   * overrides — never overwriting a neo_seo value. Then fetches a sample of
   * pages as visitors see them and as neo_seo will print them, and writes the
   * differences to a report. Uninstalls nothing: metatag, schema_metatag and
   * neo_metatag stay until someone has read the report.
   *
   * With --dry-run the configuration is applied only for as long as the diff
   * takes and then restored, and no content is touched. Run it on a copy of
   * the site, not production: for those seconds the converted configuration
   * is live.
   */
  #[CLI\Command(name: 'neo-seo:migrate')]
  #[CLI\Option(name: 'dry-run', description: 'Diff the conversion, then restore the configuration; write no content.')]
  #[CLI\Option(name: 'report', description: 'Where to write the Markdown report.')]
  #[CLI\Option(name: 'per-bundle', description: 'How many pages of each bundle the diff samples.')]
  #[CLI\Option(name: 'insecure', description: 'Skip TLS certificate checks (self-signed local sites).')]
  #[CLI\Option(name: 'no-diff', description: 'Convert without fetching pages.')]
  #[CLI\Usage(name: 'drush neo-seo:migrate --dry-run --insecure', description: 'See what the switch would change.')]
  public function migrate(
    array $options = [
      'dry-run' => FALSE,
      'report' => NULL,
      'per-bundle' => 1,
      'insecure' => FALSE,
      'no-diff' => FALSE,
    ],
  ): int {
    if (!$this->migrator->available()) {
      $this->logger()->notice(dt('metatag is not installed: there is nothing to migrate.'));
      return self::EXIT_SUCCESS;
    }
    $dryRun = (bool) $options['dry-run'];
    $plan = $this->migrator->plan();
    $counts = array_count_values(array_column($plan['report'], 'outcome'));
    foreach ($counts as $outcome => $count) {
      $this->logger()->notice(dt('@outcome: @count', ['@outcome' => $outcome, '@count' => $count]));
    }
    foreach ($plan['report'] as $line) {
      if ($line['outcome'] === 'unconverted') {
        $this->logger()->warning(dt('@set @tag: @note', [
          '@set' => $line['set'],
          '@tag' => $line['tag'],
          '@note' => $line['note'],
        ]));
      }
    }

    $snapshot = $dryRun ? $this->snapshot($plan) : [];
    try {
      $written = $this->migrator->applyConfig($plan);
      $this->logger()->notice(dt('@verb @count configuration objects.', [
        '@verb' => $dryRun ? 'Applied for the diff' : 'Wrote',
        '@count' => count($written),
      ]));
      if (!$dryRun) {
        $updated = $this->migrator->applyContent();
        $this->logger()->notice(dt('Copied metatag values into the SEO overrides of @count pages.', ['@count' => $updated]));
      }
      else {
        foreach ($plan['content'] as $entityTypeId => $count) {
          $this->logger()->notice(dt('@count @type entities have metatag values to copy (not copied in a dry run).', [
            '@count' => $count,
            '@type' => $entityTypeId,
          ]));
        }
      }

      $diffs = [];
      if (!$options['no-diff']) {
        $paths = $this->sampler->paths(max(1, (int) ($options['per-bundle'] ?: 1)));
        $this->logger()->notice(dt('Fetching @count pages twice…', ['@count' => count($paths)]));
        $diffs = $this->headDiff->run($paths, !$options['insecure']);
      }
    }
    finally {
      if ($dryRun) {
        $this->restore($snapshot);
        $this->logger()->notice(dt('Restored the configuration.'));
      }
    }

    $report = HeadDiff::report($diffs, $plan);
    $path = $options['report'] ?: $this->fileSystem->getTempDirectory() . '/neo_seo-migration-' . date('Ymd-His') . '.md';
    file_put_contents($path, $report);
    $changed = count(array_filter($diffs, static fn(array $diff): bool => $diff['head'] || $diff['graph']));
    $this->logger()->success(dt('Report: @path (@changed of @total pages differ).', [
      '@path' => $path,
      '@changed' => $changed,
      '@total' => count($diffs),
    ]));
    return self::EXIT_SUCCESS;
  }

  /**
   * The raw data of every config object a plan may write.
   *
   * @return array<string, array|null>
   *   Config name => its data, NULL where it does not exist yet.
   */
  private function snapshot(array $plan): array {
    $names = ['neo_seo.settings'];
    foreach (array_keys($plan['defaults']) as $id) {
      $names[] = "neo_seo.defaults.$id";
    }
    foreach (array_keys($plan['mappings']) as $key) {
      [$entityTypeId, $bundle] = explode(':', $key, 2);
      if ($entityTypeId === 'node') {
        $names[] = "node.type.$bundle";
      }
      elseif ($entityTypeId === 'taxonomy_term') {
        $names[] = "taxonomy.vocabulary.$bundle";
      }
      elseif ($entityTypeId === 'media') {
        $names[] = "media.type.$bundle";
      }
    }
    $snapshot = [];
    foreach (array_unique($names) as $name) {
      $config = $this->configFactory->get($name);
      $snapshot[$name] = $config->isNew() ? NULL : $config->getRawData();
    }
    return $snapshot;
  }

  /**
   * Puts config back the way a snapshot found it.
   */
  private function restore(array $snapshot): void {
    foreach ($snapshot as $name => $data) {
      $config = $this->configFactory->getEditable($name);
      if ($data === NULL) {
        $config->delete();
      }
      else {
        $config->setData($data)->save();
      }
    }
  }

  /**
   * Validates the structured data of a sample of the site's pages.
   *
   * Fetches the front page, the newest published pages of every bundle with
   * SEO, every Views page and a page that does not exist, and checks each
   * one's JSON-LD: every @id reference must resolve inside its graph, and the
   * schema.org and Google rich-result rules must pass. Exits non-zero on any
   * error.
   */
  #[CLI\Command(name: 'neo-seo:validate', aliases: ['nsv'])]
  #[CLI\Option(name: 'url', description: 'Check only these paths (repeatable).')]
  #[CLI\Option(name: 'per-bundle', description: 'How many pages of each bundle to sample.')]
  #[CLI\Option(name: 'insecure', description: 'Skip TLS certificate checks (self-signed local sites).')]
  #[CLI\Option(name: 'warnings', description: 'List warnings as well as errors.')]
  #[CLI\Usage(name: 'drush neo-seo:validate', description: 'Validate a sample of every kind of page.')]
  #[CLI\Usage(name: 'drush neo-seo:validate --url=/about --url=/insights', description: 'Validate two pages.')]
  #[CLI\FieldLabels(labels: [
    'page' => 'Page',
    'status' => 'HTTP',
    'nodes' => 'Nodes',
    'dangling' => 'Dangling',
    'errors' => 'Errors',
    'warnings' => 'Warnings',
    'path' => 'URL',
  ])]
  public function validate(
    array $options = [
      'url' => [],
      'per-bundle' => 2,
      'insecure' => FALSE,
      'warnings' => FALSE,
      'format' => 'table',
    ],
  ): CommandResult {
    if ($reason = $this->validator->unavailable()) {
      throw new \RuntimeException($reason);
    }
    $paths = $options['url'] ? array_combine($options['url'], $options['url']) : $this->sampler->paths(max(1, (int) ($options['per-bundle'] ?: 2)));
    $verify = !$options['insecure'];
    $this->logger()->notice(dt('Fetching @count pages…', ['@count' => count($paths)]));
    $results = $this->validator->validate($this->validator->fetch($paths, $verify), $verify);

    $rows = [];
    $failed = FALSE;
    foreach ($results as $label => $result) {
      $errors = array_filter($result['issues'], static fn(array $issue): bool => $issue['severity'] === 'ERROR');
      $warnings = array_filter($result['issues'], static fn(array $issue): bool => $issue['severity'] !== 'ERROR');
      $expectNotFound = $label === 'not found';
      $statusOk = $expectNotFound ? $result['status'] === 404 : $result['status'] >= 200 && $result['status'] < 300;
      $missing = $statusOk && !$expectNotFound && $result['scripts'] === 0;
      $failed = $failed || $errors || $result['dangling'] || !$statusOk || $missing;

      foreach ($result['dangling'] as $reference) {
        $this->logger()->error(dt('@page: dangling reference @ref', ['@page' => $label, '@ref' => $reference]));
      }
      foreach ($errors as $issue) {
        $this->logger()->error($this->describe($label, $issue));
      }
      if ($options['warnings']) {
        foreach ($warnings as $issue) {
          $this->logger()->warning($this->describe($label, $issue));
        }
      }
      if (!$statusOk) {
        $this->logger()->error(dt('@page: unexpected HTTP status @status', [
          '@page' => $label,
          '@status' => $result['status'],
        ]));
      }
      if ($missing) {
        $this->logger()->error(dt('@page: no JSON-LD on the page', ['@page' => $label]));
      }
      $rows[] = [
        'page' => $label,
        'status' => $result['status'],
        'nodes' => count($result['types']),
        'dangling' => count($result['dangling']),
        'errors' => count($errors),
        'warnings' => count($warnings),
        'path' => $result['url'],
      ];
    }
    if ($failed) {
      $this->logger()->error(dt('Structured data has errors.'));
    }
    else {
      $this->logger()->success(dt('Structured data is valid on all @count pages.', ['@count' => count($rows)]));
    }
    return CommandResult::dataWithExitCode(new RowsOfFields($rows), $failed ? self::EXIT_FAILURE : self::EXIT_SUCCESS);
  }

  /**
   * One line describing a validation issue on a page.
   */
  private function describe(string $page, array $issue): string {
    return dt('@page: @type @message', [
      '@page' => $page,
      '@type' => $issue['type'],
      '@message' => $issue['message'],
    ]);
  }

}
