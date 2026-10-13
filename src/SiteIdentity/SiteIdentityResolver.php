<?php

declare(strict_types=1);

namespace Drupal\neo_seo\SiteIdentity;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Utility\Token;
use Drupal\neo_seo\Graph\PageContext;
use Drupal\neo_seo\MediaImage;
use Drupal\neo_seo\TokenText;
use Drupal\neo_settings\SettingsRepository;

/**
 * Resolves the site identity settings into plain values for one page.
 *
 * Tokens are replaced, the logo media becomes a URL with its dimensions, and
 * everything read is recorded on the page context.
 */
class SiteIdentityResolver {

  /**
   * Constructs the resolver.
   *
   * @param \Drupal\neo_settings\SettingsRepository $settings
   *   The neo_seo settings repository.
   * @param \Drupal\Core\Utility\Token $token
   *   The token service.
   * @param \Drupal\neo_seo\MediaImage $mediaImage
   *   The media image helper.
   * @param \Drupal\neo_seo\SiteIdentity\IdentitySources $sources
   *   Site settings and the favicon, where the identity takes from them.
   */
  public function __construct(
    protected SettingsRepository $settings,
    protected Token $token,
    protected MediaImage $mediaImage,
    protected IdentitySources $sources,
  ) {}

  /**
   * The site identity, resolved for a page.
   *
   * @param \Drupal\neo_seo\Graph\PageContext $context
   *   The page.
   *
   * @return array{type: string, name: string, description: string, logo: ?array, same_as: string[], telephone: string, email: string, address: array<string, string>}
   *   The identity. `type` is the schema.org type to emit: "Person", or the
   *   configured Organization type. `logo` is NULL or
   *   ['url' => …, 'width' => …, 'height' => …, 'caption' => …].
   */
  public function resolve(PageContext $context): array {
    $settings = $this->settings->getActive();
    $identity = (array) $settings->getValue('identity');
    $context->addCacheableDependency($settings);
    $context->addCacheableDependency((new BubbleableMetadata())->addCacheTags(['config:neo_seo.settings']));

    $type = ($identity['type'] ?? 'organization') === 'person'
      ? 'Person'
      : (OrganizationTypes::isValid((string) ($identity['organization_type'] ?? '')) ? $identity['organization_type'] : 'Organization');

    $address = [];
    foreach ((array) ($identity['address'] ?? []) as $key => $value) {
      $address[$key] = $this->replace((string) $value, $context);
    }

    $name = $this->replace((string) ($identity['name'] ?? ''), $context) ?: $context->siteName;
    return [
      'type' => $type,
      'name' => $name,
      'description' => $this->replace((string) ($identity['description'] ?? ''), $context),
      'logo' => ($identity['logo_source'] ?? 'media') === 'favicon'
        ? $this->faviconLogo($context, $name)
        : $this->logo((string) ($identity['logo'] ?? ''), $context),
      'same_as' => $this->sameAs($identity, $context),
      'telephone' => $this->replace((string) ($identity['telephone'] ?? ''), $context),
      'email' => $this->replace((string) ($identity['email'] ?? ''), $context),
      'address' => array_filter($address),
    ];
  }

  /**
   * Replaces site tokens in a value; an unresolved token becomes nothing.
   *
   * A token that resolves to nothing can strand its separator — the comma in
   * "[site:address:street_1], [site:address:street_2]" when there is no second
   * line — so the result is tidied.
   */
  protected function replace(string $value, PageContext $context): string {
    if ($value === '' || !str_contains($value, '[')) {
      return trim($value);
    }
    $bubbleable = new BubbleableMetadata();
    $replaced = $this->token->replacePlain($value, [], ['clear' => TRUE], $bubbleable);
    $context->addCacheableDependency($bubbleable);
    return TokenText::tidy($replaced);
  }

  /**
   * The social profiles: site settings' when used, then the ones typed here.
   *
   * One URL once, whichever list it is in.
   *
   * @return string[]
   *   Absolute URLs.
   */
  protected function sameAs(array $identity, PageContext $context): array {
    $urls = [];
    if (!empty($identity['same_as_site_settings'])) {
      $cacheability = new CacheableMetadata();
      $type = (string) ($identity['same_as_type'] ?? '') ?: (string) $this->sources->defaultSocialType();
      $urls = array_column($this->sources->socialLinks($type, $cacheability), 'url');
      $context->addCacheableDependency($cacheability);
    }
    foreach ((array) ($identity['same_as'] ?? []) as $url) {
      $urls[] = $this->replace((string) $url, $context);
    }
    $unique = [];
    foreach (array_filter($urls) as $url) {
      $unique[IdentitySources::normalize($url)] ??= $url;
    }
    return array_values($unique);
  }

  /**
   * The favicon's largest icon as the logo; none until a favicon is uploaded.
   *
   * An icon has no caption of its own, so it is captioned with the name it
   * stands for.
   */
  protected function faviconLogo(PageContext $context, string $name): ?array {
    $cacheability = new CacheableMetadata();
    $logo = $this->sources->faviconLogo($cacheability);
    $context->addCacheableDependency($cacheability);
    return $logo ? $logo + ['caption' => $name] : NULL;
  }

  /**
   * The logo media as an image: URL, dimensions and caption.
   */
  protected function logo(string $uuid, PageContext $context): ?array {
    $image = $this->mediaImage->resolve($uuid, $context);
    return $image ? [
      'url' => $image['url'],
      'width' => $image['width'],
      'height' => $image['height'],
      'caption' => $image['caption'],
    ] : NULL;
  }

}
