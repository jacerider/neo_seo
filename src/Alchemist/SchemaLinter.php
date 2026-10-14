<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Alchemist;

use Drupal\neo_alchemist\Lint\ComponentLinterInterface;
use Drupal\neo_seo\ComponentSchema\DeclarationLinter;

/**
 * The `seo` declaration's checks, in `drush neo:alchemist:validate`.
 *
 * Registered only while neo_alchemist is enabled (NeoSeoServiceProvider).
 */
class SchemaLinter implements ComponentLinterInterface {

  /**
   * Constructs the linter.
   *
   * @param \Drupal\neo_seo\ComponentSchema\DeclarationLinter $linter
   *   The declaration's static checks.
   */
  public function __construct(
    protected DeclarationLinter $linter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function lint(string $component, array $definition): array {
    return $this->linter->lint($definition);
  }

}
