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
 *   its own size.
 */
class IdentitySources {

  /**
   * The site settings type the social profiles are read from by default.
   */
  public const SOCIAL_TYPE = 'social';

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
