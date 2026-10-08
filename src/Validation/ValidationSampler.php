<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Validation;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Picks the pages that stand for a site: a few of each kind.
 *
 * The front page, the newest published entities of every bundle that has SEO,
 * every Views page that takes no arguments, and a page that does not exist.
 * Used by `drush neo-seo:validate` and the migration diff, so both look at the
 * same pages.
 */
class ValidationSampler {

  /**
   * The path sampled as the not-found page.
   */
  public const NOT_FOUND_PATH = '/neo-seo-validate/not-found';

  /**
   * Constructs the sampler.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $bundleInfo
   *   The bundle info service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityTypeBundleInfoInterface $bundleInfo,
    protected ConfigFactoryInterface $configFactory,
    protected ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * The sample, path keyed by a label saying what it stands for.
   *
   * @param int $perBundle
   *   How many entities of each bundle.
   *
   * @return array<string, string>
   *   Label => site-relative path.
   */
  public function paths(int $perBundle = 2): array {
    $paths = ['front' => '/'];
    $entityTypes = (array) $this->configFactory->get('neo_seo.settings')->get('override_entity_types');
    $entityTypes = $entityTypes ?: ['node', 'taxonomy_term'];
    foreach ($entityTypes as $entityTypeId) {
      $entityType = $this->entityTypeManager->getDefinition($entityTypeId, FALSE);
      if (!$entityType instanceof ContentEntityTypeInterface || !$entityType->hasLinkTemplate('canonical')) {
        continue;
      }
      $storage = $this->entityTypeManager->getStorage($entityTypeId);
      foreach (array_keys($this->bundleInfo->getBundleInfo($entityTypeId)) as $bundle) {
        $query = $storage->getQuery()->accessCheck(TRUE)->sort($entityType->getKey('id'), 'DESC')->range(0, $perBundle);
        if ($entityType->hasKey('bundle')) {
          $query->condition($entityType->getKey('bundle'), $bundle);
        }
        if ($entityType->hasKey('published')) {
          $query->condition($entityType->getKey('published'), 1);
        }
        foreach ($storage->loadMultiple($query->execute()) as $entity) {
          $paths["$entityTypeId:$bundle:" . $entity->id()] = $entity->toUrl()->toString();
        }
      }
    }
    if ($this->moduleHandler->moduleExists('views')) {
      foreach ($this->entityTypeManager->getStorage('view')->loadMultiple() as $view) {
        if (!$view->status()) {
          continue;
        }
        foreach ($view->get('display') as $displayId => $display) {
          $path = $display['display_options']['path'] ?? '';
          $enabled = $display['display_options']['enabled'] ?? TRUE;
          // Admin listings are not pages a search engine sees.
          $public = $path !== '' && !str_contains($path, '%') && !preg_match('#^/?admin(/|$)#', $path);
          if (($display['display_plugin'] ?? '') === 'page' && $enabled && $public) {
            $paths['view:' . $view->id() . ':' . $displayId] = '/' . ltrim($path, '/');
          }
        }
      }
    }
    $paths['not found'] = static::NOT_FOUND_PATH;
    return $paths;
  }

}
