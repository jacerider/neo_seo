<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Drush\Commands;

use Consolidation\AnnotatedCommand\CommandResult;
use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
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
  ) {
    parent::__construct();
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
