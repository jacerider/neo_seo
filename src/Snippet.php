<?php

declare(strict_types=1);

namespace Drupal\neo_seo;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Url;
use Drupal\neo_seo\Graph\PageContextFactory;
use Drupal\neo_seo\Head\HeadValuesResolver;
use Drupal\token\TokenEntityMapperInterface;

/**
 * The snippet input: a title or description field with tokens as pills.
 *
 * The field stays a plain text field holding token text; the `neo_seo/snippet`
 * library draws it as an editor of named pills, with a picker of the snippet
 * variables (SEO settings) and every token of the page's type. A per-page
 * field also carries the template it overrides: shown while the field is
 * empty, and an edit equal to it saves nothing.
 */
class Snippet {

  /**
   * Constructs the service.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory, for the snippet variables.
   * @param \Drupal\neo_seo\Graph\PageContextFactory $contexts
   *   Builds an entity's page context.
   * @param \Drupal\neo_seo\Head\HeadValuesResolver $headValues
   *   The templates a page's head is built from.
   * @param \Drupal\token\TokenEntityMapperInterface $tokenEntityMapper
   *   The entity type → token type mapper.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected PageContextFactory $contexts,
    protected HeadValuesResolver $headValues,
    protected TokenEntityMapperInterface $tokenEntityMapper,
  ) {}

  /**
   * The templates an entity's per-page fields override.
   *
   * @return array<string, string>
   *   Field (title, description) => template; empty when none applies.
   */
  public function templates(ContentEntityInterface $entity): array {
    $templates = $this->headValues->templates($this->contexts->forEntity($entity));
    return [
      'title' => $templates['title'] ?? '',
      'description' => $templates['description'] ?? '',
    ];
  }

  /**
   * The token type of an entity type's tokens.
   */
  public function tokenType(string $entityTypeId): string {
    return (string) $this->tokenEntityMapper->getTokenTypeForEntityType($entityTypeId, TRUE);
  }

  /**
   * Makes a text element a snippet input.
   *
   * @param array $element
   *   A textfield or textarea.
   * @param string[] $types
   *   The token types it may use, besides the global ones.
   * @param string|null $template
   *   The template it overrides (a per-page field), or NULL (a template).
   */
  public function attach(array &$element, array $types, ?string $template = NULL): void {
    $settings = $this->configFactory->get('neo_seo.settings');
    $element['#attributes']['data-neo-seo-snippet'] = Json::encode([
      'types' => array_values(array_filter($types)),
      'template' => $template,
    ]);
    $element['#attached']['library'][] = 'neo_seo/snippet';
    $tokens = Url::fromRoute('neo_seo.tokens');
    $access = $tokens->access(NULL, TRUE);
    $element['#attached']['drupalSettings']['neoSeoSnippet'] = [
      'variables' => array_values((array) ($settings->get('variables') ?? [])),
      'tokensUrl' => $access instanceof AccessResultInterface && $access->isAllowed()
        ? $tokens->toString(TRUE)->getGeneratedUrl()
        : NULL,
    ];
    $element['#cache']['tags'] = array_merge($element['#cache']['tags'] ?? [], $settings->getCacheTags());
  }

  /**
   * A per-page value, or NULL when it is just the template it overrides.
   */
  public static function override(?string $value, string $template): ?string {
    $value = trim((string) $value);
    if ($value === '' || ($template !== '' && static::normalize($value) === static::normalize($template))) {
      return NULL;
    }
    return $value;
  }

  /**
   * Token text compared loosely: runs of space count as one.
   */
  protected static function normalize(string $text): string {
    return trim((string) preg_replace('/\s+/u', ' ', $text));
  }

}
