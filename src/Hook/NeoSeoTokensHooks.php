<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Hook;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_seo\SeoOverrides;

/**
 * The [neo-seo:*] tokens: an entity's SEO overrides.
 *
 * The token module only exposes base fields for its own entity types, so the
 * overrides need tokens of their own. Like [neo:*], they read the first
 * entity in the token data, whatever its type. [neo-seo:separator] is the
 * site's title separator, and needs no entity. [neo-seo:slug] is the one that
 * never comes back empty: a URL alias pattern built on it must not lose a
 * segment, so it falls back to the entity's label.
 */
class NeoSeoTokensHooks {

  /**
   * Constructs the hooks.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory, for the separator.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * The tokens, override property => label.
   */
  protected const TOKENS = [
    'title' => 'SEO title',
    'description' => 'SEO description',
    'keyphrase' => 'Focus keyphrase',
    'page-type' => 'Page type',
    'article-type' => 'Article type',
  ];

  /**
   * Implements hook_token_info().
   */
  #[Hook('token_info')]
  public function tokenInfo(): array {
    $tokens = [];
    foreach (static::TOKENS as $name => $label) {
      $tokens[$name] = [
        // phpcs:ignore Drupal.Semantics.FunctionT.NotLiteralString
        'name' => new TranslatableMarkup($label),
        'description' => new TranslatableMarkup('The value an editor set for this page, if any.'),
      ];
    }
    $tokens['separator'] = [
      'name' => new TranslatableMarkup('Separator'),
      'description' => new TranslatableMarkup('The title separator chosen in the SEO settings.'),
    ];
    $tokens['slug'] = [
      'name' => new TranslatableMarkup('URL slug'),
      'description' => new TranslatableMarkup('The slug an editor set for this page, else its title. Put it in a URL alias pattern where the title was to let editors change that part.'),
    ];
    return [
      'types' => [
        'neo-seo' => [
          'name' => new TranslatableMarkup('SEO overrides'),
          'description' => new TranslatableMarkup('Per-page SEO values of the entity being displayed.'),
        ],
      ],
      'tokens' => ['neo-seo' => $tokens],
    ];
  }

  /**
   * Implements hook_tokens().
   */
  #[Hook('tokens')]
  public function tokens(string $type, array $tokens, array $data, array $options, BubbleableMetadata $bubbleable): array {
    if ($type !== 'neo-seo') {
      return [];
    }
    $replacements = [];
    if (isset($tokens['separator'])) {
      $settings = $this->configFactory->get('neo_seo.settings');
      $bubbleable->addCacheableDependency($settings);
      $replacements[$tokens['separator']] = (string) ($settings->get('separator') ?? '|');
    }
    $entity = NULL;
    $labelled = NULL;
    foreach ($data as $value) {
      if ($value instanceof FieldableEntityInterface && $value->hasField(SeoOverrides::FIELD)) {
        $entity = $value;
        break;
      }
      $labelled ??= $value instanceof EntityInterface ? $value : NULL;
    }
    $labelled = $entity ?? $labelled;
    if (isset($tokens['slug']) && $labelled) {
      $bubbleable->addCacheableDependency($labelled);
      $slug = trim((string) (SeoOverrides::of($entity)['slug'] ?? ''));
      $replacements[$tokens['slug']] = $slug !== '' ? $slug : (string) $labelled->label();
    }
    if (!$entity) {
      return $replacements;
    }
    $bubbleable->addCacheableDependency($entity);
    $overrides = SeoOverrides::of($entity);
    foreach ($tokens as $name => $original) {
      $property = str_replace('-', '_', $name);
      if (isset(static::TOKENS[$name]) && $overrides[$property] !== NULL) {
        $replacements[$original] = (string) $overrides[$property];
      }
    }
    return $replacements;
  }

}
