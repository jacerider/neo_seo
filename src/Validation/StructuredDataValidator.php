<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Validation;

use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Url;
use GuzzleHttp\ClientInterface;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Fetches pages and checks their structured data.
 *
 * Drupal does the network: it fetches each page over HTTP, the way a search
 * engine sees it, and keeps a copy of the schema.org vocabulary. The checks
 * run in node (`tools/validate/validate.mjs`): every {"@id"} reference must
 * resolve inside its graph, and @adobe/structured-data-validator applies
 * schema.org and Google rich-result rules.
 */
class StructuredDataValidator {

  /**
   * Where the schema.org vocabulary comes from.
   */
  public const VOCABULARY_URL = 'https://schema.org/version/latest/schemaorg-all-https.jsonld';

  /**
   * How long a downloaded vocabulary is used before it is fetched again.
   */
  protected const VOCABULARY_MAX_AGE = 30 * 86400;

  /**
   * Constructs the validator.
   *
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   The HTTP client.
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   The file system.
   * @param \Drupal\Core\Extension\ModuleExtensionList $moduleList
   *   The module list, to find the validator script.
   */
  public function __construct(
    protected ClientInterface $httpClient,
    protected FileSystemInterface $fileSystem,
    protected ModuleExtensionList $moduleList,
  ) {}

  /**
   * The directory holding the node validator.
   */
  public function toolsDirectory(): string {
    return DRUPAL_ROOT . '/' . $this->moduleList->getPath('neo_seo') . '/tools/validate';
  }

  /**
   * Why the validator cannot run, or NULL when it can.
   */
  public function unavailable(): ?string {
    if (!(new ExecutableFinder())->find('node')) {
      return 'node is not installed or not on the PATH. Run this inside the environment that has it (for DDEV: ddev drush neo-seo:validate).';
    }
    if (!is_dir($this->toolsDirectory() . '/node_modules/@adobe/structured-data-validator')) {
      return sprintf('The validator packages are not installed. Run: npm ci --prefix %s (or npm install the first time).', $this->toolsDirectory());
    }
    return NULL;
  }

  /**
   * Fetches pages over HTTP.
   *
   * @param array<string, string> $paths
   *   Label => site-relative path.
   * @param bool $verify
   *   Whether to verify TLS certificates.
   * @param array<string, string> $headers
   *   Extra request headers.
   * @param array<string, string> $query
   *   Extra query parameters, such as a page-cache buster.
   *
   * @return array<string, array{url: string, status: int, html: string}>
   *   Label => the fetched page.
   */
  public function fetch(array $paths, bool $verify = TRUE, array $headers = [], array $query = []): array {
    $base = rtrim(Url::fromRoute('<front>', [], ['absolute' => TRUE])->toString(), '/');
    $pages = [];
    foreach ($paths as $label => $path) {
      $url = preg_match('#^https?://#', $path) ? $path : $base . '/' . ltrim($path, '/');
      try {
        $response = $this->httpClient->request('GET', $url, [
          'http_errors' => FALSE,
          'verify' => $verify,
          'timeout' => 60,
          'headers' => ['Accept' => 'text/html'] + $headers,
          'query' => $query,
        ]);
        $pages[$label] = [
          'url' => $url,
          'status' => $response->getStatusCode(),
          'html' => (string) $response->getBody(),
        ];
      }
      catch (\Throwable $e) {
        $pages[$label] = ['url' => $url, 'status' => 0, 'html' => '', 'error' => $e->getMessage()];
      }
    }
    return $pages;
  }

  /**
   * Validates fetched pages.
   *
   * @param array<string, array{url: string, status: int, html: string}> $pages
   *   Label => fetched page.
   * @param bool $verify
   *   Whether to verify TLS when downloading the vocabulary.
   *
   * @return array<string, array{url: string, status: int, scripts: int, types: string[], dangling: string[], issues: array<int, array{severity: string, type: string, message: string}>}>
   *   Label => result.
   *
   * @throws \RuntimeException
   *   When the validator cannot run.
   */
  public function validate(array $pages, bool $verify = TRUE): array {
    if ($reason = $this->unavailable()) {
      throw new \RuntimeException($reason);
    }
    $input = $this->fileSystem->tempnam('temporary://', 'neo_seo_pages_');
    file_put_contents($input, json_encode(array_values($pages)));
    try {
      $process = new Process(
        [
          'node',
          $this->toolsDirectory() . '/validate.mjs',
          $this->fileSystem->realpath($input),
          $this->vocabulary($verify),
        ],
        $this->toolsDirectory(),
        timeout: 600,
      );
      $process->run();
      $output = json_decode($process->getOutput(), TRUE);
      if (!is_array($output) || isset($output['error'])) {
        throw new \RuntimeException('The validator failed: ' . ($output['error'] ?? trim($process->getErrorOutput() ?: $process->getOutput())));
      }
    }
    finally {
      $this->fileSystem->delete($input);
    }
    return array_combine(array_keys($pages), $output);
  }

  /**
   * The schema.org vocabulary file, downloaded at most once a month.
   *
   * @return string
   *   Its real path.
   */
  protected function vocabulary(bool $verify): string {
    $directory = 'temporary://neo_seo';
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
    $path = $this->fileSystem->realpath($directory) . '/schemaorg-all-https.jsonld';
    if (!is_file($path) || filemtime($path) < time() - static::VOCABULARY_MAX_AGE) {
      $response = $this->httpClient->request('GET', static::VOCABULARY_URL, ['verify' => $verify, 'timeout' => 120]);
      file_put_contents($path, (string) $response->getBody());
    }
    return $path;
  }

}
