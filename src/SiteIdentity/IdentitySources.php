<?php

declare(strict_types=1);

namespace Drupal\neo_seo\SiteIdentity;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;

/**
 * Where the site identity can come from besides its own settings.
 *
 * Both are optional, and neither is a dependency:
 * - neo_site_settings: the social profiles, as the filled link fields of one
 *   site settings type (a `social` type ships with the module), in the order
 *   its display gives them;
 * - neo_favicon: the logo, as the largest icon of the uploaded favicon, at
 *   its own size;
 * - neo_site_settings with office_hours: a local business's opening hours, as
 *   the weekly hours of one office hours field.
 */
class IdentitySources {

  /**
   * The site settings type the social profiles are read from by default.
   */
  public const SOCIAL_TYPE = 'social';

  /**
   * Schema.org day names, by office_hours day number (0 is Sunday).
   */
  protected const DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

  public function __construct(
    protected ModuleHandlerInterface $moduleHandler,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityDisplayRepositoryInterface $entityDisplayRepository,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected FileUrlGeneratorInterface $fileUrlGenerator,
    protected ConfigFactoryInterface $configFactory,
    protected ?object $faviconManager = NULL,
  ) {}

  /**
   * Whether site settings can provide the social profiles.
   */
  public function hasSiteSettings(): bool {
    return $this->moduleHandler->moduleExists('neo_site_settings')
      && $this->entityTypeManager->hasDefinition('neo_site_settings');
  }

  /**
   * The site settings types that have link fields, keyed by id.
   *
   * @return array<string, string>
   *   Labels.
   */
  public function socialTypes(): array {
    if (!$this->hasSiteSettings()) {
      return [];
    }
    $types = [];
    foreach ($this->entityTypeManager->getStorage('neo_site_settings_type')->loadMultiple() as $id => $type) {
      foreach ($this->entityFieldManager->getFieldDefinitions('neo_site_settings', (string) $id) as $definition) {
        if ($definition->getType() === 'link') {
          $types[(string) $id] = (string) $type->label();
          break;
        }
      }
    }
    asort($types);
    return $types;
  }

  /**
   * The type to read social profiles from when none is chosen yet.
   */
  public function defaultSocialType(): ?string {
    $types = $this->socialTypes();
    if (isset($types[static::SOCIAL_TYPE])) {
      return static::SOCIAL_TYPE;
    }
    return array_key_first($types);
  }

  /**
   * The social profiles a site settings type holds.
   *
   * Every filled link field, in the order the type's display gives them.
   *
   * @param string $type
   *   The site settings type.
   * @param \Drupal\Core\Cache\CacheableMetadata|null $cacheability
   *   Collects what the list depends on.
   *
   * @return array<int, array{label: string, url: string}>
   *   The profiles, with absolute URLs.
   */
  public function socialLinks(string $type, ?CacheableMetadata $cacheability = NULL): array {
    if (!$this->hasSiteSettings() || $type === '') {
      return [];
    }
    // The list tag, not the entity's: settings never saved are a new entity,
    // and a new entity carries no tags at all.
    $cacheability?->addCacheTags(['neo_site_settings_list:' . $type]);
    $entity = $this->entityTypeManager->getStorage('neo_site_settings')->loadByType($type);
    if (!$entity instanceof FieldableEntityInterface) {
      return [];
    }
    $cacheability?->addCacheableDependency($entity);
    $display = $this->entityDisplayRepository->getViewDisplay('neo_site_settings', $type);
    $links = [];
    foreach ($entity->getFieldDefinitions() as $name => $definition) {
      if ($definition->getType() !== 'link' || $entity->get($name)->isEmpty()) {
        continue;
      }
      /** @var \Drupal\link\LinkItemInterface $item */
      $item = $entity->get($name)->first();
      if (!$item->uri) {
        continue;
      }
      try {
        $url = $item->getUrl()->setAbsolute()->toString(TRUE);
      }
      catch (\Exception) {
        continue;
      }
      $cacheability?->addCacheableDependency($url);
      $links[] = [
        'weight' => (int) ($display->getComponent($name)['weight'] ?? 0),
        'label' => (string) $definition->getLabel(),
        'url' => $url->getGeneratedUrl(),
      ];
    }
    usort($links, fn(array $a, array $b) => $a['weight'] <=> $b['weight']);
    return array_map(fn(array $link) => ['label' => $link['label'], 'url' => $link['url']], $links);
  }

  /**
   * The office hours fields site settings hold, as "type.field" => label.
   *
   * @return array<string, string>
   *   Labels, "Type: Field".
   */
  public function hoursFields(): array {
    if (!$this->hasSiteSettings()) {
      return [];
    }
    $fields = [];
    foreach ($this->entityTypeManager->getStorage('neo_site_settings_type')->loadMultiple() as $id => $type) {
      foreach ($this->entityFieldManager->getFieldDefinitions('neo_site_settings', (string) $id) as $name => $definition) {
        if ($definition->getType() === 'office_hours') {
          $fields["$id.$name"] = $type->label() . ': ' . $definition->getLabel();
        }
      }
    }
    asort($fields);
    return $fields;
  }

  /**
   * The weekly opening hours an office hours field in site settings holds.
   *
   * Read as office_hours stores them: a day number (0 is Sunday; exception
   * dates are left out), and start and end times as numbers (900 is 09:00).
   * A row without both times is a closed day; an all-day row is open around
   * the clock. Days with the same hours share one specification, so the
   * result reads as a business would say it ("Monday to Friday, 9 to 5").
   *
   * @param string $source
   *   The field, as "type.field".
   * @param \Drupal\Core\Cache\CacheableMetadata|null $cacheability
   *   Collects what the hours depend on.
   *
   * @return list<array{@type: string, dayOfWeek: string[], opens: string, closes: string}>
   *   OpeningHoursSpecification nodes, in week order of their first day.
   */
  public function openingHours(string $source, ?CacheableMetadata $cacheability = NULL): array {
    [$type, $field] = explode('.', $source, 2) + [1 => ''];
    if (!$this->hasSiteSettings() || $type === '' || $field === '') {
      return [];
    }
    $cacheability?->addCacheTags(['neo_site_settings_list:' . $type]);
    $entity = $this->entityTypeManager->getStorage('neo_site_settings')->loadByType($type);
    if (!$entity instanceof FieldableEntityInterface || !$entity->hasField($field) || $entity->get($field)->getFieldDefinition()->getType() !== 'office_hours') {
      return [];
    }
    $cacheability?->addCacheableDependency($entity);
    $groups = [];
    // Monday first, as a week of opening hours is read.
    $rows = $entity->get($field)->getValue();
    $order = fn(array $row) => [((int) ($row['day'] ?? 0) + 6) % 7, (int) ($row['starthours'] ?? 0)];
    usort($rows, fn(array $a, array $b) => $order($a) <=> $order($b));
    foreach ($rows as $row) {
      $day = $row['day'] ?? NULL;
      if (!is_numeric($day) || (int) $day < 0 || (int) $day > 6) {
        continue;
      }
      $times = $this->schemaTimes($row);
      if ($times === NULL) {
        continue;
      }
      $key = implode('-', $times);
      $groups[$key] ??= [
        '@type' => 'OpeningHoursSpecification',
        'dayOfWeek' => [],
        'opens' => $times[0],
        'closes' => $times[1],
      ];
      $groups[$key]['dayOfWeek'][] = static::DAYS[(int) $day];
    }
    foreach ($groups as &$group) {
      $group['dayOfWeek'] = array_values(array_unique($group['dayOfWeek']));
    }
    return array_values($groups);
  }

  /**
   * An office hours row's opening and closing times, or NULL when closed.
   *
   * @return array{0: string, 1: string}|null
   *   "HH:MM" times.
   */
  protected function schemaTimes(array $row): ?array {
    if (!empty($row['all_day'])) {
      return ['00:00', '23:59'];
    }
    $start = $row['starthours'] ?? NULL;
    $end = $row['endhours'] ?? NULL;
    if (!is_numeric($start) || !is_numeric($end) || (int) $start < 0 || (int) $end < 0) {
      return NULL;
    }
    $format = fn(int $time) => sprintf('%02d:%02d', intdiv($time, 100) % 24, $time % 100);
    return [$format((int) $start), $format((int) $end)];
  }

  /**
   * Whether the favicon can provide the logo.
   */
  public function hasFavicon(): bool {
    // The service exists only while neo_favicon is enabled.
    return $this->faviconManager !== NULL;
  }

  /**
   * The favicon's largest icon, as the logo.
   *
   * At its own size: a logo is never cropped to a share card's shape.
   *
   * @param \Drupal\Core\Cache\CacheableMetadata|null $cacheability
   *   Collects what the logo depends on.
   *
   * @return array{url: string, width: int, height: int}|null
   *   The icon, or NULL when no favicon has been uploaded.
   */
  public function faviconLogo(?CacheableMetadata $cacheability = NULL): ?array {
    if (!$this->hasFavicon()) {
      return NULL;
    }
    $cacheability?->addCacheableDependency($this->configFactory->get('neo_favicon.settings'));
    $largest = NULL;
    foreach ((array) $this->faviconManager->getImages() as $uri => $size) {
      $width = (int) ($size['width'] ?? 0);
      if ($largest === NULL || $width > $largest['width']) {
        $largest = ['uri' => (string) $uri, 'width' => $width, 'height' => (int) ($size['height'] ?? 0)];
      }
    }
    if ($largest === NULL) {
      return NULL;
    }
    return [
      'url' => $this->fileUrlGenerator->generateAbsoluteString($largest['uri']),
      'width' => $largest['width'],
      'height' => $largest['height'],
    ];
  }

  /**
   * Turns on what is available where the site has nothing of its own.
   *
   * The logo comes from the favicon when no media logo is chosen. The social
   * profiles come from site settings when the list typed here is empty, or
   * holds only URLs site settings already has — then the list is emptied, as
   * nothing in it would be lost. A list with anything else is left alone.
   * Run on install, by the update, and when either module is installed.
   *
   * @param array $identity
   *   The identity settings.
   *
   * @return array
   *   The identity settings, with whatever applies turned on.
   */
  public function adopt(array $identity): array {
    if ($this->hasFavicon() && ($identity['logo'] ?? '') === '' && ($identity['logo_source'] ?? 'media') === 'media') {
      $identity['logo_source'] = 'favicon';
    }
    $type = $this->defaultSocialType();
    if ($type !== NULL && empty($identity['same_as_site_settings'])) {
      $typed = array_values(array_filter(array_map('trim', (array) ($identity['same_as'] ?? []))));
      $known = array_map([static::class, 'normalize'], array_column($this->socialLinks($type), 'url'));
      $extra = array_filter($typed, fn(string $url) => !in_array(static::normalize($url), $known, TRUE));
      if (!$extra) {
        $identity['same_as_site_settings'] = TRUE;
        $identity['same_as_type'] = $type;
        $identity['same_as'] = [];
      }
    }
    return $identity;
  }

  /**
   * Applies adopt() to the saved settings, saving only what it changes.
   */
  public function adoptInConfig(): void {
    $config = $this->configFactory->getEditable('neo_seo.settings');
    $identity = (array) $config->get('identity') + [
      'logo_source' => 'media',
      'same_as_site_settings' => FALSE,
      'same_as_type' => '',
    ];
    $adopted = $this->adopt($identity);
    if ($adopted !== $identity) {
      $config->set('identity', $adopted)->save();
    }
  }

  /**
   * A URL compared loosely: case, scheme and a trailing slash aside.
   */
  public static function normalize(string $url): string {
    $url = strtolower(trim($url));
    $url = (string) preg_replace('#^https?://(www\.)?#', '', $url);
    return rtrim($url, '/');
  }

}
