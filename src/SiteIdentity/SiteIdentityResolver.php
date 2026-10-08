<?php

declare(strict_types=1);

namespace Drupal\neo_seo\SiteIdentity;

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
   */
  public function __construct(
    protected SettingsRepository $settings,
    protected Token $token,
    protected MediaImage $mediaImage,
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

    return [
      'type' => $type,
      'name' => $this->replace((string) ($identity['name'] ?? ''), $context) ?: $context->siteName,
      'description' => $this->replace((string) ($identity['description'] ?? ''), $context),
      'logo' => $this->logo((string) ($identity['logo'] ?? ''), $context),
      'same_as' => array_values(array_filter(array_map(
        fn($url) => $this->replace((string) $url, $context),
        (array) ($identity['same_as'] ?? []),
      ))),
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
