<?php

declare(strict_types=1);

namespace Drupal\neo_seo;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Utility\Token;
use Drupal\pathauto\AliasCleanerInterface;
use Drupal\pathauto\AliasUniquifierInterface;
use Drupal\pathauto\PathautoPatternInterface;
use Drupal\token\TokenEntityMapperInterface;

/**
 * Moves a site's pathauto patterns onto the slug tokens (drush neo-seo:slugs).
 *
 * Two swaps, each only where it is exact: a last segment that is the
 * entity's label token ([node:title], [term:name]) becomes [neo:slug], and
 * core's parents path ([node:menu-link:parents:join-path],
 * [term:parents:join-path]) becomes [neo:parents:join-path]. Anything else is
 * left for a person. A pattern is left alone, and the reason given, where
 * editors could not use a slug: the entity type has no SEO overrides, or a
 * bundle's form hides them.
 *
 * Both tokens give today's values while no slug is set, which the plan checks
 * page by page: it builds every page's alias from the old pattern and the new
 * and lists any that differ. It never re-aliases a page.
 */
class PageUrlPatterns {

  use StringTranslationTrait;

  /**
   * Core's parents path tokens, which [neo:parents:join-path] replaces.
   */
  public const PARENTS = [
    '[node:menu-link:parents:join-path]',
    '[term:parents:join-path]',
  ];

  /**
   * How many pages are loaded at once while checking.
   */
  protected const CHUNK = 50;

  /**
   * Constructs the service.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The entity field manager.
   * @param \Drupal\Core\Entity\EntityDisplayRepositoryInterface $displayRepository
   *   The entity display repository, for the bundles' forms.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $bundleInfo
   *   The bundle info, for a pattern that holds for every bundle.
   * @param \Drupal\Core\Utility\Token $token
   *   The token service.
   * @param \Drupal\token\TokenEntityMapperInterface $tokenEntityMapper
   *   The token entity mapper.
   * @param \Drupal\neo_seo\PageUrl $pageUrl
   *   The page URL service.
   * @param \Drupal\pathauto\AliasCleanerInterface|null $aliasCleaner
   *   Pathauto's alias cleaner, when pathauto is installed.
   * @param \Drupal\pathauto\AliasUniquifierInterface|null $aliasUniquifier
   *   Pathauto's alias uniquifier, when pathauto is installed.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected EntityDisplayRepositoryInterface $displayRepository,
    protected EntityTypeBundleInfoInterface $bundleInfo,
    protected Token $token,
    protected TokenEntityMapperInterface $tokenEntityMapper,
    protected PageUrl $pageUrl,
    protected ?AliasCleanerInterface $aliasCleaner = NULL,
    protected ?AliasUniquifierInterface $aliasUniquifier = NULL,
  ) {}

  /**
   * Whether pathauto is there to have patterns.
   */
  public function available(): bool {
    return $this->aliasCleaner !== NULL && $this->entityTypeManager->hasDefinition('pathauto_pattern');
  }

  /**
   * What swapping would do to each of the site's enabled patterns.
   *
   * @param string|null $only
   *   One pattern's machine name, or NULL for all.
   * @param bool $check
   *   Whether to build every page's alias from both patterns.
   *
   * @return array<string, array{label: string, from: string, to: string, status: string, reason: string, checked: int, moving: string[]}>
   *   Keyed by pattern id. Status is 'swap', 'done' (already on the tokens)
   *   or 'skip' (with the reason). Checked counts the pages built from the
   *   pattern; moving lists those whose alias would change, "old → new".
   */
  public function plan(?string $only = NULL, bool $check = TRUE): array {
    if (!$this->available()) {
      return [];
    }
    $patterns = $this->entityTypeManager->getStorage('pathauto_pattern')->loadByProperties(['status' => TRUE]);
    uasort($patterns, fn($a, $b) => $a->getWeight() <=> $b->getWeight());
    $plan = [];
    foreach ($patterns as $id => $pattern) {
      if ($only !== NULL && $id !== $only) {
        continue;
      }
      $from = (string) $pattern->getPattern();
      $row = [
        'label' => (string) $pattern->label(),
        'from' => $from,
        'to' => $from,
        'status' => 'skip',
        'reason' => '',
        'checked' => 0,
        'moving' => [],
      ];
      $plan[$id] = $this->planOne($pattern, $row, $check);
    }
    return $plan;
  }

  /**
   * Swaps the patterns a plan marks 'swap'.
   *
   * @return int
   *   How many patterns were changed.
   */
  public function apply(array $plan): int {
    $storage = $this->entityTypeManager->getStorage('pathauto_pattern');
    $count = 0;
    foreach ($plan as $id => $row) {
      if ($row['status'] === 'swap' && $pattern = $storage->load($id)) {
        $pattern->setPattern($row['to'])->save();
        $count++;
      }
    }
    return $count;
  }

  /**
   * The bundles a pattern is limited to, or NULL for every bundle.
   *
   * @return string[]|null
   *   The bundle ids.
   */
  public static function bundlesOf(PathautoPatternInterface $pattern): ?array {
    foreach ($pattern->getSelectionConditions() as $condition) {
      if (str_starts_with($condition->getPluginId(), 'entity_bundle')) {
        return array_values(array_filter(array_map('strval', (array) ($condition->getConfiguration()['bundles'] ?? []))));
      }
    }
    return NULL;
  }

  /**
   * A pattern string with the slug tokens in place of core's.
   *
   * @param string $pattern
   *   The pattern.
   * @param string $label
   *   The entity's label token, such as [node:title].
   */
  public static function swap(string $pattern, string $label): string {
    $pattern = str_replace(self::PARENTS, PageUrl::PARENTS_TOKEN, $pattern);
    return (string) preg_replace('#(^|/)' . preg_quote($label, '#') . '(/?)$#', '$1' . PageUrl::TOKEN . '$2', $pattern);
  }

  /**
   * Fills in one pattern's row of the plan.
   */
  protected function planOne(PathautoPatternInterface $pattern, array $row, bool $check): array {
    [$kind, $entityTypeId] = explode(':', (string) $pattern->getType(), 2) + [1 => ''];
    $entityType = $kind === 'canonical_entities' ? $this->entityTypeManager->getDefinition($entityTypeId, FALSE) : NULL;
    if (!$entityType || !$entityType->hasKey('label')) {
      return ['reason' => (string) $this->t('Not a pattern for content with titles.')] + $row;
    }
    $label = '[' . $this->tokenEntityMapper->getTokenTypeForEntityType($entityTypeId) . ':' . $entityType->getKey('label') . ']';
    $row['to'] = static::swap($row['from'], $label);
    if ($row['to'] === $row['from']) {
      $uses = str_contains($row['from'], PageUrl::TOKEN);
      return [
        'status' => $uses ? 'done' : 'skip',
        'reason' => $uses
          ? (string) $this->t('Already uses @token.', ['@token' => PageUrl::TOKEN])
          : (string) $this->t('Its last segment is not @label; edit it by hand.', ['@label' => $label]),
      ] + $row;
    }
    if (!isset($this->entityFieldManager->getFieldStorageDefinitions($entityTypeId)[SeoOverrides::FIELD])) {
      return ['reason' => (string) $this->t('@type has no SEO overrides; turn them on in the SEO settings.', ['@type' => $entityType->getLabel()])] + $row;
    }
    $bundles = static::bundlesOf($pattern) ?? array_keys($this->bundleInfo->getBundleInfo($entityTypeId));
    foreach ($bundles as $bundle) {
      if (!$this->displayRepository->getFormDisplay($entityTypeId, $bundle)->getComponent(SeoOverrides::FIELD)) {
        return ['reason' => (string) $this->t('The SEO field is hidden on the @bundle form.', ['@bundle' => $bundle])] + $row;
      }
    }
    $row['status'] = 'swap';
    return $check ? $this->check($pattern, $entityTypeId, static::bundlesOf($pattern), $row) : $row;
  }

  /**
   * Builds each page's alias from the old pattern and the new.
   */
  protected function check(PathautoPatternInterface $pattern, string $entityTypeId, ?array $bundles, array $row): array {
    $storage = $this->entityTypeManager->getStorage($entityTypeId);
    $query = $storage->getQuery()->accessCheck(FALSE);
    if ($bundles !== NULL && $bundleKey = $storage->getEntityType()->getKey('bundle')) {
      $query->condition($bundleKey, $bundles, 'IN');
    }
    foreach (array_chunk($query->execute(), static::CHUNK) as $ids) {
      foreach ($storage->loadMultiple($ids) as $entity) {
        if (!$entity instanceof ContentEntityInterface || !$pattern->applies($entity) || $this->pageUrl->isCustom($entity)) {
          continue;
        }
        $row['checked']++;
        $old = $this->build($entity, $row['from']);
        $new = $this->build($entity, $row['to']);
        if ($old !== $new) {
          $row['moving'][] = $old . ' → ' . $new;
        }
      }
      $storage->resetCache($ids);
    }
    return $row;
  }

  /**
   * The alias pathauto would build for a page from a pattern string.
   *
   * As PathautoGenerator::createEntityAlias() builds one, without its alter
   * hooks.
   */
  protected function build(ContentEntityInterface $entity, string $pattern): string {
    $langcode = $entity->language()->getId();
    if ($langcode === LanguageInterface::LANGCODE_NOT_APPLICABLE) {
      $langcode = LanguageInterface::LANGCODE_NOT_SPECIFIED;
    }
    $alias = $this->token->replace($pattern, [
      $this->tokenEntityMapper->getTokenTypeForEntityType($entity->getEntityTypeId()) => $entity,
    ], [
      'clear' => TRUE,
      'callback' => [$this->aliasCleaner, 'cleanTokenValues'],
      'langcode' => $langcode,
      'pathauto' => TRUE,
    ], new BubbleableMetadata());
    $alias = $this->aliasCleaner->cleanAlias($alias);
    $this->aliasUniquifier?->uniquify($alias, '/' . $entity->toUrl()->getInternalPath(), $langcode);
    return $alias;
  }

}
