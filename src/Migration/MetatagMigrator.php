<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Migration;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\Entity\ThirdPartySettingsInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\neo_seo\Entity\SeoDefaults;
use Drupal\neo_seo\Mapping\BundleMappingRepository;
use Drupal\neo_seo\Schema\SchemaTypes;
use Drupal\neo_seo\SeoOverrides;

/**
 * Converts a site's metatag setup into neo_seo's.
 *
 * Three parts, each reported line by line:
 * - metatag defaults → neo_seo default templates (same ids: global, front,
 *   node, node__insight …);
 * - schema_metatag settings → bundle mappings and site identity;
 * - metatag field values → SEO overrides.
 *
 * Nothing is lost silently: every metatag value is reported as converted,
 * derived (neo_seo computes it), dropped on purpose, or unconverted (someone
 * has to decide). Existing neo_seo values are never overwritten.
 */
class MetatagMigrator {

  /**
   * Metatag tags that become a neo_seo template field.
   */
  protected const FIELDS = [
    'title' => 'title',
    'description' => 'description',
    'og_title' => 'social_title',
    'robots' => 'robots',
    'og_image' => 'image',
    'og_image_url' => 'image',
    'twitter_cards_image' => 'image',
    'image_src' => 'image',
  ];

  /**
   * Metatag tags neo_seo computes itself, and how.
   */
  protected const DERIVED = [
    'canonical_url' => 'the canonical URL of the page context',
    'og_url' => 'the canonical URL',
    'shortlink' => 'dropped: it publishes the unaliased path',
    'og_site_name' => 'the site name',
    'og_locale' => 'the page language',
    'og_image_width' => 'the share image',
    'og_image_height' => 'the share image',
    'og_description' => 'the description',
    'twitter_cards_description' => 'the description',
    'twitter_cards_title' => 'the social title',
    'twitter_cards_type' => 'summary_large_image when there is an image',
    'article_published_time' => 'the bundle’s date published',
    'article_modified_time' => 'the bundle’s date modified',
  ];

  /**
   * Schema groups the graph builds on its own, from site identity and pages.
   */
  protected const ENGINE_GROUPS = ['schema_web_page', 'schema_web_site'];

  /**
   * Constructs the migrator.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The entity field manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\neo_seo\Mapping\BundleMappingRepository $mapping
   *   The bundle mapping repository.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected ConfigFactoryInterface $configFactory,
    protected BundleMappingRepository $mapping,
  ) {}

  /**
   * Whether there is a metatag setup to migrate.
   */
  public function available(): bool {
    return $this->entityTypeManager->hasDefinition('metatag_defaults');
  }

  /**
   * The plan: what would be written, and what each metatag value becomes.
   *
   * @return array{defaults: array<string, array<string, string>>, mappings: array<string, array<string, mixed>>, identity: array<string, mixed>, entity_types: string[], content: array<string, int>, report: array<int, array{set: string, tag: string, value: string, outcome: string, note: string}>}
   *   The plan. `report` outcomes: converted, derived, dropped, unconverted.
   */
  public function plan(): array {
    $plan = [
      'defaults' => [],
      'mappings' => [],
      'identity' => [],
      'entity_types' => [],
      'content' => [],
      'report' => [],
    ];
    foreach ($this->entityTypeManager->getStorage('metatag_defaults')->loadMultiple() as $id => $defaults) {
      if (!$defaults->status()) {
        continue;
      }
      $groups = [];
      foreach ((array) $defaults->get('tags') as $tag => $value) {
        $value = $this->decode($value);
        if (str_starts_with($tag, 'schema_')) {
          $groups[$this->schemaGroup($tag)][$tag] = $value;
          continue;
        }
        $this->planTag($plan, (string) $id, $tag, $value);
      }
      foreach ($groups as $group => $tags) {
        $this->planSchemaGroup($plan, (string) $id, $group, $tags);
      }
    }
    $this->planContent($plan);
    return $plan;
  }

  /**
   * Writes the plan's configuration. Never overwrites a neo_seo value.
   *
   * @return string[]
   *   The config names written, for a caller that wants to undo them.
   */
  public function applyConfig(array $plan): array {
    $written = [];
    foreach ($plan['defaults'] as $id => $values) {
      $defaults = SeoDefaults::load($id) ?? SeoDefaults::create(['id' => $id, 'label' => $this->label($id)]);
      $existing = $defaults->getValues();
      $merged = $existing;
      foreach ($values as $field => $template) {
        if (($existing[$field] ?? '') === '') {
          $merged[$field] = $template;
        }
      }
      if ($defaults->isNew() || $merged !== $existing) {
        $defaults->setValues($merged)->save();
        $written[] = $defaults->getConfigDependencyName();
      }
    }
    foreach ($plan['mappings'] as $key => $settings) {
      [$entityTypeId, $bundle] = explode(':', $key, 2);
      $bundleEntity = $this->mapping->bundleEntity($entityTypeId, $bundle);
      if (!$bundleEntity instanceof ThirdPartySettingsInterface) {
        continue;
      }
      $changed = FALSE;
      foreach ($settings as $setting => $value) {
        if ($bundleEntity->getThirdPartySetting('neo_seo', $setting) === NULL) {
          $bundleEntity->setThirdPartySetting('neo_seo', $setting, $value);
          $changed = TRUE;
        }
      }
      if ($changed) {
        $bundleEntity->save();
        $written[] = $bundleEntity->getConfigDependencyName();
      }
    }
    $settings = $this->configFactory->getEditable('neo_seo.settings');
    $changed = FALSE;
    $defaultsShipped = [
      'name' => '[site:name]',
      'description' => '[site:slogan]',
      'organization_type' => 'Organization',
    ];
    foreach ($plan['identity'] as $key => $value) {
      $current = $settings->get("identity.$key");
      $untouched = $current === NULL || $current === '' || $current === [] || ($defaultsShipped[$key] ?? NULL) === $current;
      if ($untouched && $current !== $value) {
        $settings->set("identity.$key", $value);
        $changed = TRUE;
      }
    }
    $enabled = (array) $settings->get('override_entity_types');
    $missing = array_values(array_diff($plan['entity_types'], $enabled));
    if ($missing) {
      $settings->set('override_entity_types', array_values(array_merge($enabled, $missing)));
      $changed = TRUE;
    }
    if ($changed) {
      $settings->save();
      $written[] = 'neo_seo.settings';
    }
    return $written;
  }

  /**
   * Copies metatag field values into SEO overrides, where none are set.
   *
   * @return int
   *   How many entity translations were updated.
   */
  public function applyContent(): int {
    $updated = 0;
    foreach ($this->metatagFields() as $entityTypeId => $fieldNames) {
      $storage = $this->entityTypeManager->getStorage($entityTypeId);
      foreach ($fieldNames as $fieldName) {
        $ids = $storage->getQuery()->accessCheck(FALSE)->exists($fieldName)->execute();
        foreach ($storage->loadMultiple($ids) as $entity) {
          if (!$entity->hasField(SeoOverrides::FIELD)) {
            continue;
          }
          foreach ($entity->getTranslationLanguages() as $language) {
            $translation = $entity->getTranslation($language->getId());
            $overrides = $this->overridesFrom($this->decode($translation->get($fieldName)->value));
            $current = SeoOverrides::of($translation);
            $changes = array_filter($overrides, static fn($value, $key) => $current[$key] === NULL || $current[$key] === FALSE, ARRAY_FILTER_USE_BOTH);
            if ($changes) {
              $translation->set(SeoOverrides::FIELD, $changes + array_filter($current, static fn($value) => $value !== NULL));
              $translation->save();
              $updated++;
            }
          }
        }
      }
    }
    return $updated;
  }

  /**
   * Plans one plain metatag tag of a defaults set.
   */
  protected function planTag(array &$plan, string $set, string $tag, mixed $value): void {
    $string = is_array($value) ? implode(', ', array_filter($value)) : trim((string) $value);
    if ($string === '') {
      return;
    }
    if (isset(static::FIELDS[$tag])) {
      $field = static::FIELDS[$tag];
      if ($field === 'image' && isset($plan['defaults'][$set]['image'])) {
        $plan['report'][] = $this->line($set, $tag, $string, 'derived', 'the share image is already ' . $plan['defaults'][$set]['image']);
        return;
      }
      $plan['defaults'][$set][$field] = $string;
      $plan['report'][] = $this->line($set, $tag, $string, 'converted', "template field $field");
      return;
    }
    if ($tag === 'og_type') {
      $this->planOgType($plan, $set, $string);
      return;
    }
    if (isset(static::DERIVED[$tag])) {
      $plan['report'][] = $this->line($set, $tag, $string, 'derived', static::DERIVED[$tag]);
      return;
    }
    $plan['report'][] = $this->line($set, $tag, $string, 'unconverted', 'no neo_seo head tag; add a head tag plugin if it is still needed');
  }

  /**
   * Plans og:type: articles and profiles are bundle mappings in neo_seo.
   */
  protected function planOgType(array &$plan, string $set, string $type): void {
    $bundle = $this->bundleOf($set);
    if ($type === 'article' && $bundle) {
      $plan['mappings'][$bundle]['article_type'] ??= 'Article';
      $plan['report'][] = $this->line($set, 'og_type', $type, 'converted', "$bundle article type Article (og:type follows it)");
    }
    elseif ($type === 'profile' && $bundle) {
      $plan['mappings'][$bundle]['page_type'] = 'ProfilePage';
      $plan['report'][] = $this->line($set, 'og_type', $type, 'converted', "$bundle page type ProfilePage (og:type follows it)");
    }
    else {
      $plan['defaults'][$set]['og_type'] = $type;
      $plan['report'][] = $this->line($set, 'og_type', $type, 'converted', 'template field og_type');
    }
  }

  /**
   * Plans one schema_metatag group of a defaults set.
   */
  protected function planSchemaGroup(array &$plan, string $set, string $group, array $tags): void {
    $bundle = $this->bundleOf($set);
    $report = function (string $outcome, string $note, ?array $only = NULL) use (&$plan, $set, $tags): void {
      foreach ($only ?? array_keys($tags) as $tag) {
        $plan['report'][] = $this->line($set, $tag, $this->flatten($tags[$tag] ?? ''), $outcome, $note);
      }
    };

    if (in_array($group, static::ENGINE_GROUPS, TRUE)) {
      $report('derived', 'built by the graph engine from site identity and the page; SearchAction is dropped (Google retired the sitelinks search box)');
      return;
    }
    if ($group === 'schema_organization' && $set === 'global') {
      $this->planIdentity($plan, $tags);
      return;
    }
    if ($group === 'schema_article' && $bundle) {
      $type = (string) ($tags['schema_article_type'] ?? 'Article');
      $plan['mappings'][$bundle]['article_type'] = isset(SchemaTypes::articleTypes()[$type]) ? $type : 'Article';
      $report('converted', "$bundle article type " . $plan['mappings'][$bundle]['article_type'], ['schema_article_type']);
      $rest = array_diff(array_keys($tags), ['schema_article_type', 'schema_article_author']);
      $report('derived', 'built by the Article piece from the page, its mapped dates and image', $rest);
      if (isset($tags['schema_article_author'])) {
        [$entityTypeId, $bundleId] = explode(':', $bundle, 2);
        $authors = $this->mapping->forBundle($entityTypeId, $bundleId)->authorField;
        $authors
          ? $report('derived', "authors come from $authors", ['schema_article_author'])
          : $report('unconverted', "choose an Authors field for $bundle in its SEO settings; without one the Article has only its publisher", ['schema_article_author']);
      }
      return;
    }
    if ($group === 'schema_person' && $bundle) {
      $plan['mappings'][$bundle]['page_type'] = 'ProfilePage';
      if (preg_match('/^\[[a-z_]+:(field_[a-z0-9_]+)\]$/', (string) ($tags['schema_person_job_title'] ?? ''), $matches)) {
        $plan['mappings'][$bundle]['job_title_field'] = $matches[1];
        $report('converted', "$bundle job title field {$matches[1]}", ['schema_person_job_title']);
      }
      $report('converted', "$bundle page type ProfilePage", ['schema_person_type']);
      $rest = array_diff(array_keys($tags), ['schema_person_type', 'schema_person_job_title']);
      $report('derived', 'built by the people piece from the person page', $rest);
      return;
    }
    // A whole group with no generic piece is one decision, so one line.
    $type = (string) ($tags[$group . '_type'] ?? $group);
    $plan['report'][] = $this->line($set, $group . '_*', $type, 'unconverted', sprintf(
      '%d tags: no generic %s piece. It is covered only if a graph piece plugin in code emits it.',
      count($tags),
      $type,
    ));
  }

  /**
   * Plans site identity from schema_organization.
   */
  protected function planIdentity(array &$plan, array $tags): void {
    $map = [
      'schema_organization_name' => 'name',
      'schema_organization_description' => 'description',
      'schema_organization_telephone' => 'telephone',
      'schema_organization_email' => 'email',
    ];
    foreach ($tags as $tag => $value) {
      $line = fn(string $outcome, string $note) => $this->line('global', $tag, $this->flatten($value), $outcome, $note);
      if (isset($map[$tag])) {
        $plan['identity'][$map[$tag]] = trim((string) $value);
        $plan['report'][] = $line('converted', 'site identity ' . $map[$tag]);
      }
      elseif ($tag === 'schema_organization_same_as') {
        $plan['identity']['same_as'] = array_values(array_filter(array_map('trim', is_array($value) ? $value : explode(',', (string) $value))));
        $plan['report'][] = $line('converted', 'site identity social profiles');
      }
      elseif ($tag === 'schema_organization_address' && is_array($value)) {
        $plan['identity']['address'] = array_map('trim', array_filter([
          'street' => $value['streetAddress'] ?? '',
          'locality' => $value['addressLocality'] ?? '',
          'region' => $value['addressRegion'] ?? '',
          'postal_code' => $value['postalCode'] ?? '',
          'country' => $value['addressCountry'] ?? '',
        ], static fn($part) => is_string($part) && trim($part) !== ''));
        $plan['report'][] = $line('converted', 'site identity address');
      }
      elseif ($tag === 'schema_organization_type') {
        $plan['report'][] = $line('derived', 'site identity type (kept as configured)');
      }
      elseif ($tag === 'schema_organization_logo') {
        $plan['report'][] = $line('unconverted', 'pick a logo media item in site identity; neo_seo does not use the favicon');
      }
      elseif (in_array($tag, ['schema_organization_id', 'schema_organization_url'], TRUE)) {
        $plan['report'][] = $line('derived', 'the site URL and #organization');
      }
      else {
        $plan['report'][] = $line('unconverted', 'no site identity setting for it');
      }
    }
  }

  /**
   * Counts the metatag field values waiting to become overrides.
   */
  protected function planContent(array &$plan): void {
    foreach ($this->metatagFields() as $entityTypeId => $fieldNames) {
      $plan['entity_types'][] = $entityTypeId;
      $storage = $this->entityTypeManager->getStorage($entityTypeId);
      $count = 0;
      foreach ($fieldNames as $fieldName) {
        $count += (int) $storage->getQuery()->accessCheck(FALSE)->exists($fieldName)->count()->execute();
      }
      $plan['content'][$entityTypeId] = $count;
    }
    $plan['entity_types'] = array_values(array_unique($plan['entity_types']));
  }

  /**
   * The metatag fields on each entity type.
   *
   * @return array<string, string[]>
   *   Entity type id => field names.
   */
  protected function metatagFields(): array {
    $fields = [];
    foreach ($this->entityFieldManager->getFieldMapByFieldType('metatag') as $entityTypeId => $map) {
      $fields[$entityTypeId] = array_keys($map);
    }
    return $fields;
  }

  /**
   * The overrides a metatag field value carries.
   */
  protected function overridesFrom(mixed $tags): array {
    if (!is_array($tags)) {
      return [];
    }
    $overrides = [];
    if (!empty($tags['title'])) {
      $overrides['title'] = (string) $tags['title'];
    }
    if (!empty($tags['description'])) {
      $overrides['description'] = (string) $tags['description'];
    }
    $robots = is_array($tags['robots'] ?? NULL) ? implode(',', $tags['robots']) : (string) ($tags['robots'] ?? '');
    if (str_contains($robots, 'noindex')) {
      $overrides['noindex'] = TRUE;
    }
    return $overrides;
  }

  /**
   * Decodes a stored metatag value: serialized, JSON, or plain.
   */
  protected function decode(mixed $value): mixed {
    if (!is_string($value)) {
      return $value;
    }
    if (preg_match('/^(a|s|i|b):\d*[:;]/', $value)) {
      $decoded = @unserialize($value, ['allowed_classes' => FALSE]);
      if ($decoded !== FALSE || $value === 'b:0;') {
        return $decoded;
      }
    }
    if ($value !== '' && ($value[0] === '{' || $value[0] === '[')) {
      $decoded = json_decode($value, TRUE);
      if (json_last_error() === JSON_ERROR_NONE) {
        return $decoded;
      }
    }
    return $value;
  }

  /**
   * The schema_metatag group of a tag, such as schema_article.
   */
  protected function schemaGroup(string $tag): string {
    $groups = [
      'schema_web_page',
      'schema_web_site',
      'schema_job_posting',
      'schema_organization',
      'schema_article',
      'schema_person',
    ];
    foreach ($groups as $group) {
      if (str_starts_with($tag, $group . '_')) {
        return $group;
      }
    }
    return implode('_', array_slice(explode('_', $tag), 0, 2));
  }

  /**
   * The bundle a defaults set is for, as "entity_type:bundle", or NULL.
   */
  protected function bundleOf(string $set): ?string {
    if (!str_contains($set, '__')) {
      return NULL;
    }
    [$entityTypeId, $bundle] = explode('__', $set, 2);
    return "$entityTypeId:$bundle";
  }

  /**
   * A label for a new defaults set.
   */
  protected function label(string $id): string {
    return match ($id) {
      'global' => 'Global',
      'front' => 'Front page',
      '403' => '403 access denied',
      '404' => '404 page not found',
      default => $id,
    };
  }

  /**
   * One report line.
   */
  protected function line(string $set, string $tag, string $value, string $outcome, string $note): array {
    return ['set' => $set, 'tag' => $tag, 'value' => mb_substr($value, 0, 160), 'outcome' => $outcome, 'note' => $note];
  }

  /**
   * A value as one line of text.
   */
  protected function flatten(mixed $value): string {
    return is_array($value) ? (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : trim((string) $value);
  }

}
