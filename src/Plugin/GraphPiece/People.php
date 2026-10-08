<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Plugin\GraphPiece;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_seo\Attribute\GraphPiece;
use Drupal\neo_seo\Graph\GraphPieceBase;
use Drupal\neo_seo\Graph\PageContext;
use Drupal\neo_seo\Graph\People as PeopleService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The Person nodes a page needs: who its profile is about, and its authors.
 */
#[GraphPiece(
  id: 'people',
  label: new TranslatableMarkup('People'),
  weight: -40,
)]
class People extends GraphPieceBase {

  /**
   * The people service.
   */
  protected PeopleService $people;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->people = $container->get('neo_seo.people');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function isNeeded(PageContext $context): bool {
    return $context->entity !== NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function generate(PageContext $context): array {
    $people = [];
    if ($profile = $this->people->profile($context)) {
      $people[$profile->uuid()] = $profile;
    }
    if ($this->headValues->resolve($context)->isArticle()) {
      foreach ($this->people->authors($context) as $author) {
        $people[$author->uuid()] = $author;
      }
    }
    return array_values(array_map(fn($person) => $this->people->node($context, $person), $people));
  }

}
