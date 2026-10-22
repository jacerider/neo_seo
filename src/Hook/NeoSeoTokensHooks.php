<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Hook;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Utility\Token;
use Drupal\neo_seo\PageUrl;
use Drupal\neo_seo\SeoOverrides;

/**
 * The [neo-seo:*] tokens: an entity's SEO overrides.
 *
 * The token module only exposes base fields for its own entity types, so the
 * overrides need tokens of their own. Like [neo:*], they read the first
 * entity in the token data, whatever its type. [neo-seo:separator] is the
 * site's title separator, and needs no entity.
 *
 * The URL tokens join the neo token type, since a URL alias pattern is not
 * about SEO: [neo:slug] and [neo:parents] (PageUrl). Neither comes back empty
 * where there is something to give, so a pattern built on them never loses a
 * segment: the slug falls back to the label, a parent's to its title.
 */
class NeoSeoTokensHooks {

  /**
   * Constructs the hooks.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory, for the separator.
   * @param \Drupal\neo_seo\PageUrl $pageUrl
   *   The page URL service, for the URL tokens.
   * @param \Drupal\Core\Utility\Token $token
   *   The token service, for [neo:parents]' array tokens.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected PageUrl $pageUrl,
    protected Token $token,
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
    return [
      'types' => [
        'neo-seo' => [
          'name' => new TranslatableMarkup('SEO overrides'),
          'description' => new TranslatableMarkup('Per-page SEO values of the entity being displayed.'),
        ],
      ],
      'tokens' => [
        'neo-seo' => $tokens,
        'neo' => [
          'slug' => [
            'name' => new TranslatableMarkup('URL slug'),
            'description' => new TranslatableMarkup('The slug an editor set for this page, else its title. Put it in a URL alias pattern where the title was to let editors change that part.'),
          ],
          'parents' => [
            'name' => new TranslatableMarkup('URL parents'),
            'description' => new TranslatableMarkup('The page’s menu parents (or a term’s parent terms), each by its slug, else its title. Use [neo:parents:join-path] in a URL alias pattern.'),
            'type' => 'array',
          ],
        ],
      ],
    ];
  }

  /**
   * Implements hook_tokens().
   */
  #[Hook('tokens')]
  public function tokens(string $type, array $tokens, array $data, array $options, BubbleableMetadata $bubbleable): array {
    if ($type === 'neo') {
      return $this->urlTokens($tokens, $data, $options, $bubbleable);
    }
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
    foreach ($data as $value) {
      if ($value instanceof FieldableEntityInterface && $value->hasField(SeoOverrides::FIELD)) {
        $entity = $value;
        break;
      }
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

  /**
   * The [neo:slug] and [neo:parents:*] tokens, for the first content entity.
   *
   * The neo module answers its own [neo:*] tokens and leaves these alone.
   */
  protected function urlTokens(array $tokens, array $data, array $options, BubbleableMetadata $bubbleable): array {
    $entity = NULL;
    foreach ($data as $value) {
      if ($value instanceof ContentEntityInterface) {
        $entity = $value;
        break;
      }
    }
    if (!$entity) {
      return [];
    }
    $bubbleable->addCacheableDependency($entity);
    $replacements = [];
    if (isset($tokens['slug'])) {
      $replacements[$tokens['slug']] = PageUrl::segment($entity);
    }
    $chained = $this->token->findWithPrefix($tokens, 'parents');
    if ($chained && $parents = $this->pageUrl->parents($entity, $options['langcode'] ?? NULL)) {
      $replacements += $this->token->generate('array', $chained, ['array' => $parents], $options, $bubbleable);
    }
    return $replacements;
  }

}
