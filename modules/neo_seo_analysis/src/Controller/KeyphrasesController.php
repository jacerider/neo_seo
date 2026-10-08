<?php

declare(strict_types=1);

namespace Drupal\neo_seo_analysis\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\neo_seo\SeoOverrides;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The focus keyphrases other pages already use.
 *
 * Feeds the "keyphrase used before" assessment. Only pages the editor may see
 * are listed, the page being edited is left out, and the list is capped: the
 * check needs to know a phrase is taken, not every page that took it.
 */
class KeyphrasesController implements ContainerInjectionInterface {

  use AutowireTrait;

  /**
   * The most pages read across all entity types.
   */
  public const LIMIT = 500;

  /**
   * Constructs the controller.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The entity field manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Lists the keyphrases, as JSON: {keyphrases: string[]}.
   *
   * Query: entity_type and exclude name the page being edited.
   */
  public function list(Request $request): JsonResponse {
    $excludeType = (string) $request->query->get('entity_type', '');
    $excludeId = (string) $request->query->get('exclude', '');
    $keyphrases = [];
    $remaining = static::LIMIT;
    foreach ((array) $this->configFactory->get('neo_seo.settings')->get('override_entity_types') as $entityTypeId) {
      if ($remaining <= 0 || !$this->hasOverrides($entityTypeId)) {
        continue;
      }
      $storage = $this->entityTypeManager->getStorage($entityTypeId);
      $idKey = (string) $this->entityTypeManager->getDefinition($entityTypeId)->getKey('id');
      $query = $storage->getQuery()
        ->accessCheck(TRUE)
        ->exists(SeoOverrides::FIELD . '.keyphrase')
        ->sort($idKey, 'DESC')
        ->range(0, $remaining);
      if ($entityTypeId === $excludeType && $excludeId !== '') {
        $query->condition($idKey, $excludeId, '<>');
      }
      $ids = $query->execute();
      $remaining -= count($ids);
      foreach (array_chunk($ids, 50) as $chunk) {
        foreach ($storage->loadMultiple($chunk) as $entity) {
          if (!$entity instanceof ContentEntityInterface || !$entity->access('view')) {
            continue;
          }
          foreach (array_keys($entity->getTranslationLanguages()) as $langcode) {
            $keyphrase = trim((string) SeoOverrides::of($entity->getTranslation($langcode))['keyphrase']);
            if ($keyphrase !== '') {
              $keyphrases[mb_strtolower($keyphrase)] = $keyphrase;
            }
          }
        }
        $storage->resetCache($chunk);
      }
    }
    $response = new CacheableJsonResponse(['keyphrases' => array_values($keyphrases)]);
    $response->addCacheableDependency((new CacheableMetadata())->setCacheMaxAge(0));
    return $response;
  }

  /**
   * Whether an entity type has the overrides field.
   */
  protected function hasOverrides(string $entityTypeId): bool {
    return $this->entityTypeManager->hasDefinition($entityTypeId)
      && isset($this->entityFieldManager->getFieldStorageDefinitions($entityTypeId)[SeoOverrides::FIELD]);
  }

}
