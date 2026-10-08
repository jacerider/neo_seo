<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Head;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Utility\Token;
use Drupal\neo_seo\Entity\SeoDefaultsInterface;
use Drupal\neo_seo\Graph\PageContext;
use Drupal\neo_seo\Mapping\BundleMappingRepository;
use Drupal\neo_seo\MediaImage;
use Drupal\neo_seo\SeoOverrides;
use Drupal\neo_seo\TokenText;
use Drupal\token\TokenEntityMapperInterface;

/**
 * Resolves a page's head values from its layers of default templates.
 *
 * The layers, lowest precedence first: global → entity type → bundle, then
 * front, then 403 or 404. A more specific layer wins field by field, and only
 * where it has a value — so a bundle that sets only a title keeps everything
 * else from the layers below it. Front, 403 and 404 sit on top of that chain
 * rather than replacing it, which is the trap metatag's defaults have.
 *
 * An editor's SEO overrides for the page sit above every layer.
 */
class HeadValuesResolver {

  /**
   * The values resolved per page context.
   *
   * @var \WeakMap<\Drupal\neo_seo\Graph\PageContext, \Drupal\neo_seo\Head\HeadValues>
   */
  private \WeakMap $resolved;

  /**
   * Constructs the resolver.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Utility\Token $token
   *   The token service.
   * @param \Drupal\token\TokenEntityMapperInterface $tokenEntityMapper
   *   The token module's entity type → token type mapper.
   * @param \Drupal\neo_seo\Head\HeadTagPluginManager $headTagManager
   *   The head tag plugin manager, for the field list.
   * @param \Drupal\neo_seo\MediaImage $mediaImage
   *   The media image helper, for an overridden share image.
   * @param \Drupal\neo_seo\Mapping\BundleMappingRepository $mapping
   *   The bundle mapping repository.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Token $token,
    protected TokenEntityMapperInterface $tokenEntityMapper,
    protected HeadTagPluginManager $headTagManager,
    protected MediaImage $mediaImage,
    protected BundleMappingRepository $mapping,
  ) {
    $this->resolved = new \WeakMap();
  }

  /**
   * The head values of a page.
   *
   * @param \Drupal\neo_seo\Graph\PageContext $context
   *   The page. What the templates read is recorded on it.
   *
   * @return \Drupal\neo_seo\Head\HeadValues
   *   The values, resolved once per context.
   */
  public function resolve(PageContext $context): HeadValues {
    return $this->resolved[$context] ??= $this->doResolve($context);
  }

  /**
   * Resolves the values of a page.
   */
  protected function doResolve(PageContext $context): HeadValues {
    $layers = $this->layers($context);
    $templates = [];
    foreach ($layers as $defaults) {
      $context->addCacheableDependency($defaults);
      foreach ($defaults->getValues() as $field => $template) {
        if ((string) $template !== '') {
          $templates[$field] = (string) $template;
        }
      }
    }

    $overrides = SeoOverrides::of($context->entity);
    foreach (['title', 'description'] as $field) {
      if ($overrides[$field] !== NULL) {
        $templates[$field] = (string) $overrides[$field];
      }
    }
    if ($overrides['noindex']) {
      $templates['robots'] = 'noindex, follow';
    }

    $data = $this->tokenData($context->entity);
    $fields = [];
    foreach (array_keys($this->headTagManager->getFields()) as $field) {
      if ($field === 'image') {
        continue;
      }
      $fields[$field] = isset($templates[$field]) ? $this->replace($templates[$field], $data, $context) : '';
    }

    // The share image and the graph's primary image are one image: the
    // editor's, else the bundle's image field, else the template.
    $image = $overrides['image'] ? $this->mediaImage->resolve((int) $overrides['image'], $context) : NULL;
    $image ??= $this->mapping->image($context);
    if (!$image && isset($templates['image'])) {
      $image = $this->image($templates['image'], $data, $context);
    }

    return new HeadValues(
      $context,
      $fields,
      $image ? array_intersect_key($image, array_flip(['url', 'width', 'height', 'alt'])) : NULL,
      array_map(static fn(SeoDefaultsInterface $defaults): string => (string) $defaults->id(), $layers),
      $this->mapping->pageType($context),
      $this->mapping->articleType($context),
      $context->entity ? $this->mapping->datePublished($context) : NULL,
      $context->entity ? $this->mapping->dateModified($context) : NULL,
    );
  }

  /**
   * The defaults that apply to a page, lowest precedence first.
   *
   * @return \Drupal\neo_seo\Entity\SeoDefaultsInterface[]
   *   The enabled defaults that exist, in order.
   */
  public function layers(PageContext $context): array {
    $ids = ['global'];
    if ($entity = $context->entity) {
      $ids[] = $entity->getEntityTypeId();
      $ids[] = $entity->getEntityTypeId() . '__' . $entity->bundle();
    }
    if ($context->isFront) {
      $ids[] = 'front';
    }
    if ($context->routeName === 'system.403') {
      $ids[] = '403';
    }
    if ($context->routeName === 'system.404') {
      $ids[] = '404';
    }
    $loaded = $this->entityTypeManager->getStorage('neo_seo_defaults')->loadMultiple($ids);
    $layers = [];
    foreach ($ids as $id) {
      if (isset($loaded[$id]) && $loaded[$id]->status()) {
        $layers[] = $loaded[$id];
      }
    }
    return $layers;
  }

  /**
   * The token data for a page: its entity, under its token type.
   */
  protected function tokenData(?ContentEntityInterface $entity): array {
    if (!$entity) {
      return [];
    }
    $type = $this->tokenEntityMapper->getTokenTypeForEntityType($entity->getEntityTypeId(), TRUE);
    return [$type => $entity];
  }

  /**
   * Replaces tokens as plain text; an unresolved token becomes nothing.
   *
   * Markup is stripped and entities decoded, because every value lands in an
   * attribute or the <title>, which escape on output; then the separators an
   * empty token strands are tidied.
   */
  protected function replace(string $template, array $data, PageContext $context): string {
    $bubbleable = new BubbleableMetadata();
    $value = $this->token->replacePlain($template, $data, ['clear' => TRUE], $bubbleable);
    $context->addCacheableDependency($bubbleable);
    return TokenText::tidy(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5));
  }

  /**
   * Resolves an image template to a URL with its dimensions and alt text.
   *
   * When the template is a single token, its `:width`, `:height` and `:alt`
   * siblings are tried too — `[neo:image]` answers the first two, image
   * fields answer all three. A sibling that does not answer with something
   * usable is left out rather than guessed: `[neo:image:alt]` answers with
   * the image URL, because `neo` reads an unknown parameter as an image style,
   * so an "alt" that looks like a URL is no alt.
   */
  protected function image(string $template, array $data, PageContext $context): ?array {
    $url = $this->replace($template, $data, $context);
    if ($url === '') {
      return NULL;
    }
    if (!preg_match('#^https?://#', $url)) {
      $url = rtrim($context->siteUrl, '/') . '/' . ltrim($url, '/');
    }
    $image = ['url' => $url, 'width' => NULL, 'height' => NULL, 'alt' => NULL];
    if (preg_match('/^\[([^\[\]]+)\]$/', trim($template), $matches)) {
      foreach (['width', 'height'] as $dimension) {
        $value = $this->replace('[' . $matches[1] . ':' . $dimension . ']', $data, $context);
        $image[$dimension] = ctype_digit($value) ? (int) $value : NULL;
      }
      $alt = $this->replace('[' . $matches[1] . ':alt]', $data, $context);
      $image['alt'] = $alt !== '' && !preg_match('#^(https?:)?/#', $alt) ? $alt : NULL;
    }
    return $image;
  }

}
