<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Graph;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\neo_seo\Head\HeadValuesResolver;
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
   * @param \Drupal\neo_seo\Head\HeadValuesResolver $headValues
   *   The head values resolver: the page's title, description, image, types
   *   and dates, shared with the head tags so the two never disagree.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected SchemaIds $ids,
    protected HeadValuesResolver $headValues,
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
      $container->get('neo_seo.head_values'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function isNeeded(PageContext $context): bool {
    return TRUE;
  }

}
