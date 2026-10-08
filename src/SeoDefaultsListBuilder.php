<?php

declare(strict_types=1);

namespace Drupal\neo_seo;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;

/**
 * Lists the default template sets, global first.
 */
class SeoDefaultsListBuilder extends ConfigEntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    return [
      'label' => $this->t('Applies to'),
      'id' => $this->t('Machine name'),
    ] + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    return [
      'label' => $entity->label(),
      'id' => $entity->id(),
    ] + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function load() {
    $entities = parent::load();
    $order = array_flip(['global', 'front', '403', '404']);
    uasort($entities, static function (EntityInterface $a, EntityInterface $b) use ($order): int {
      return [$order[$a->id()] ?? 10, $a->id()] <=> [$order[$b->id()] ?? 10, $b->id()];
    });
    return $entities;
  }

  /**
   * {@inheritdoc}
   */
  public function render() {
    $build['description'] = [
      '#markup' => '<p>' . $this->t('Each set fills the head tags of the pages it applies to. More specific sets win field by field, only where they have a value: global, then the entity type, then the bundle, then the front, 403 or 404 page. An editor’s per-page overrides win over all of them.') . '</p>',
    ];
    return $build + parent::render();
  }

}
