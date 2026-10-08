<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Graph;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base class for graph pieces.
 *
 * Gives every piece the id service, and makes a piece needed on every page
 * unless it says otherwise.
 */
abstract class GraphPieceBase extends PluginBase implements GraphPieceInterface, ContainerFactoryPluginInterface {

  /**
   * Constructs a graph piece.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin id.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\neo_seo\Graph\SchemaIds $ids
   *   The id service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected SchemaIds $ids,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('neo_seo.schema_ids'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function isNeeded(PageContext $context): bool {
    return TRUE;
  }

}
