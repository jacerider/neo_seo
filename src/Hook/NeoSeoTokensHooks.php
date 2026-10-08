<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Hook;

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
 * entity in the token data, whatever its type.
 */
class NeoSeoTokensHooks {

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
    $entity = NULL;
    foreach ($data as $value) {
      if ($value instanceof FieldableEntityInterface && $value->hasField(SeoOverrides::FIELD)) {
        $entity = $value;
        break;
      }
    }
    if (!$entity) {
      return [];
    }
    $bubbleable->addCacheableDependency($entity);
    $overrides = SeoOverrides::of($entity);
    $replacements = [];
    foreach ($tokens as $name => $original) {
      $property = str_replace('-', '_', $name);
      if (isset(static::TOKENS[$name]) && $overrides[$property] !== NULL) {
        $replacements[$original] = (string) $overrides[$property];
      }
    }
    return $replacements;
  }

}
