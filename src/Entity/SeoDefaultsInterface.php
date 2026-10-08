<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Entity;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * A layer of default templates for the head tags.
 */
interface SeoDefaultsInterface extends ConfigEntityInterface {

  /**
   * The ids that are not an entity type or bundle.
   */
  public const SPECIAL = ['global', 'front', '403', '404'];

  /**
   * The ids layered on top of every other layer.
   */
  public const ON_TOP = ['front', '403', '404'];

  /**
   * The templates, keyed by field name. Empty strings are "not set here".
   *
   * @return array<string, string>
   *   The templates.
   */
  public function getValues(): array;

  /**
   * Replaces the templates.
   *
   * @param array<string, string> $values
   *   The templates, keyed by field name.
   *
   * @return $this
   */
  public function setValues(array $values): self;

}
