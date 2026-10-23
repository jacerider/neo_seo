<?php

declare(strict_types=1);

namespace Drupal\neo_seo;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
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
 * editors could not use a slug: the entity type has no SEO overrides.
 *
 * Both tokens give today's values while no slug is set, which the plan checks
 * page by page: it builds every page's alias from the old pattern and the new
 * and lists any that differ. It never re-aliases a page.
 *
 * It also finds the form displays that still show core's URL alias field
 * where neo_seo's URL field takes its place, so the command can offer to
 * disable it: a row in Manage form display that does nothing.
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
   * The form displays that still show core's URL alias field, unused.
   *
   * On a bundle whose pages neo_seo's URL field serves (managesBundle()),
   * core's path widget is always hidden, so its row in Manage form display
   * does nothing.
   *
   * @param string|null $only
   *   One pattern's machine name: only the bundles it builds aliases for.
   *
   * @return string[]
   *   The form displays' ids, such as node.page.default.
   */
  public function pathWidgets(?string $only = NULL): array {
    $scope = NULL;
    if ($only !== NULL) {
      $pattern = $this->available() ? $this->entityTypeManager->getStorage('pathauto_pattern')->load($only) : NULL;
      if (!$pattern instanceof PathautoPatternInterface) {
        return [];
      }
      $scope = [substr((string) $pattern->getType(), strlen('canonical_entities:')), static::bundlesOf($pattern)];
    }
    $found = [];
    /** @var \Drupal\Core\Entity\Display\EntityFormDisplayInterface $display */
    foreach ($this->entityTypeManager->getStorage('entity_form_display')->loadMultiple() as $id => $display) {
      $entityTypeId = $display->getTargetEntityTypeId();
      $bundle = $display->getTargetBundle();
      if (!$display->status() || $display->getComponent('path') === NULL) {
        continue;
      }
      if ($scope && ($entityTypeId !== $scope[0] || ($scope[1] !== NULL && !in_array($bundle, $scope[1], TRUE)))) {
        continue;
      }
      if ($this->managesBundle($entityTypeId, $bundle)) {
        $found[] = (string) $id;
      }
    }
    sort($found);
    return $found;
  }

  /**
   * Hides core's URL alias field in these form displays.
   *
   * @param string[] $ids
   *   The form displays' ids, as pathWidgets() gives them.
   *
   * @return int
   *   How many form displays were changed.
   */
  public function disablePathWidgets(array $ids): int {
    $count = 0;
    foreach ($this->entityTypeManager->getStorage('entity_form_display')->loadMultiple($ids) as $display) {
      $display->removeComponent('path')->save();
      $count++;
    }
    return $count;
  }

  /**
   * Whether neo_seo's URL field serves a bundle's pages.
   *
   * As PageUrl::manages() decides for a page: SEO overrides and a path, and
   * a pattern that holds the slug or custom URLs allowed.
   */
  public function managesBundle(string $entityTypeId, string $bundle): bool {
    $fields = $this->entityFieldManager->getBaseFieldDefinitions($entityTypeId);
    if (!isset($fields[SeoOverrides::FIELD], $fields['path'])) {
      return FALSE;
    }
    foreach ($this->pageUrl->bundlePatterns($entityTypeId, $bundle) as $pattern) {
      if (str_contains($pattern, PageUrl::TOKEN)) {
        return TRUE;
      }
    }
    return $this->pageUrl->allowsCustom($entityTypeId, $bundle);
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
