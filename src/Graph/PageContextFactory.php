<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Graph;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\TitleResolverInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Path\PathMatcherInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Builds the page context for the current request.
 */
class PageContextFactory {

  /**
   * The context built for each request, keyed by request object id.
   *
   * @var array<int, \Drupal\neo_seo\Graph\PageContext>
   */
  private array $contexts = [];

  /**
   * Constructs the page context factory.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $routeMatch
   *   The current route match.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack.
   * @param \Drupal\Core\Path\PathMatcherInterface $pathMatcher
   *   The path matcher.
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   The language manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Controller\TitleResolverInterface $titleResolver
   *   The title resolver.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer, to flatten a render-array title.
   */
  public function __construct(
    protected RouteMatchInterface $routeMatch,
    protected RequestStack $requestStack,
    protected PathMatcherInterface $pathMatcher,
    protected LanguageManagerInterface $languageManager,
    protected ConfigFactoryInterface $configFactory,
    protected TitleResolverInterface $titleResolver,
    protected RendererInterface $renderer,
  ) {}

  /**
   * The context of the page the current request renders.
   *
   * @return \Drupal\neo_seo\Graph\PageContext
   *   The page context, built once per request.
   */
  public function current(): PageContext {
    $request = $this->requestStack->getCurrentRequest();
    $key = $request ? spl_object_id($request) : 0;
    return $this->contexts[$key] ??= $this->build();
  }

  /**
   * The context of an entity's canonical page, wherever the request is.
   *
   * For describing a page from somewhere else — a draft render from the edit
   * form, a validation run. A new entity has no URL yet, so its page is
   * described at the site URL.
   */
  public function forEntity(ContentEntityInterface $entity): PageContext {
    $site = $this->configFactory->get('system.site');
    $siteUrl = $this->siteUrl();
    $isFront = FALSE;
    $canonicalUrl = $siteUrl;
    if (!$entity->isNew() && $entity->hasLinkTemplate('canonical')) {
      $url = $entity->toUrl('canonical');
      $canonicalUrl = $url->setAbsolute()->toString();
      $isFront = '/' . ltrim($url->getInternalPath(), '/') === (string) $site->get('page.front');
    }
    $context = new PageContext(
      siteUrl: $siteUrl,
      canonicalUrl: $isFront ? $siteUrl : $canonicalUrl,
      langcode: $entity->language()->getId(),
      siteName: (string) $site->get('name'),
      isFront: $isFront,
      entity: $entity,
      title: $isFront ? (string) $site->get('name') : (string) $entity->label(),
      routeName: 'entity.' . $entity->getEntityTypeId() . '.canonical',
    );
    $context->addCacheableDependency($site);
    return $context;
  }

  /**
   * Builds the context from the current route.
   */
  protected function build(): PageContext {
    $site = $this->configFactory->get('system.site');
    $isFront = $this->pathMatcher->isFrontPage();
    $entity = $this->routeEntity();
    $siteUrl = $this->siteUrl();

    $canonicalUrl = match (TRUE) {
      $isFront => $siteUrl,
      $entity !== NULL => $entity->toUrl('canonical', ['absolute' => TRUE])->toString(),
      default => $this->routeUrl() ?? $siteUrl,
    };

    $context = new PageContext(
      siteUrl: $siteUrl,
      canonicalUrl: $canonicalUrl,
      langcode: $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)->getId(),
      siteName: (string) $site->get('name'),
      isFront: $isFront,
      entity: $entity,
      title: match (TRUE) {
        $isFront => (string) $site->get('name'),
        $entity !== NULL => (string) $entity->label(),
        default => $this->routeTitle(),
      },
      routeName: $this->routeMatch->getRouteName(),
      viewId: $this->routeMatch->getRouteObject()?->getDefault('view_id'),
      viewDisplayId: $this->routeMatch->getRouteObject()?->getDefault('display_id'),
      viewArguments: $this->viewArguments(),
    );
    $context->addCacheableDependency($site);
    $context->addCacheableDependency((new CacheableMetadata())->setCacheContexts([
      'route',
      'url.site',
      'languages:' . LanguageInterface::TYPE_CONTENT,
    ]));
    return $context;
  }

  /**
   * The entity whose canonical page this is, if any.
   *
   * Only the canonical route counts: an entity's edit form or Alchemist editor
   * carries the same parameter but is not a page about that entity.
   */
  protected function routeEntity(): ?ContentEntityInterface {
    $routeName = (string) $this->routeMatch->getRouteName();
    if (!preg_match('/^entity\.([^.]+)\.canonical$/', $routeName, $matches)) {
      return NULL;
    }
    $entity = $this->routeMatch->getParameter($matches[1]);
    return $entity instanceof ContentEntityInterface ? $entity : NULL;
  }

  /**
   * The title of a page that is not an entity's, as its route gives it.
   */
  protected function routeTitle(): ?string {
    $request = $this->requestStack->getCurrentRequest();
    $route = $request?->attributes->get(RouteObjectInterface::ROUTE_OBJECT);
    if (!$request || !$route) {
      return NULL;
    }
    $title = $this->titleResolver->getTitle($request, $route);
    if (is_array($title)) {
      $title = $this->renderer->renderInIsolation($title);
    }
    return $title === NULL ? NULL : trim(strip_tags((string) $title));
  }

  /**
   * A Views page's contextual arguments, in the order the view takes them.
   *
   * @return string[]
   *   The raw argument values.
   */
  protected function viewArguments(): array {
    $map = (array) ($this->routeMatch->getRouteObject()?->getOption('_view_argument_map') ?? []);
    $raw = $this->routeMatch->getRawParameters();
    $arguments = [];
    foreach ($map as $parameter) {
      if ($raw->has($parameter)) {
        $arguments[] = (string) $raw->get($parameter);
      }
    }
    return $arguments;
  }

  /**
   * The absolute site URL, ending in a slash.
   *
   * Not language-prefixed: the site and its identity are one thing across
   * every translation, so their ids must not change with the language.
   */
  protected function siteUrl(): string {
    $request = $this->requestStack->getCurrentRequest();
    if (!$request) {
      return Url::fromRoute('<front>', [], ['absolute' => TRUE])->toString();
    }
    return rtrim($request->getSchemeAndHttpHost() . $request->getBasePath(), '/') . '/';
  }

  /**
   * The absolute URL of the current route, without its query string.
   */
  protected function routeUrl(): ?string {
    try {
      return Url::fromRouteMatch($this->routeMatch)->setAbsolute()->toString();
    }
    catch (\Throwable) {
      $request = $this->requestStack->getCurrentRequest();
      return $request ? $request->getSchemeAndHttpHost() . $request->getBaseUrl() . $request->getPathInfo() : NULL;
    }
  }

}
