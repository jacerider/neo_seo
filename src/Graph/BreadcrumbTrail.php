<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Graph;

use Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface;
use Drupal\Core\Routing\RouteMatchInterface;

/**
 * The page's breadcrumb trail, as the site's breadcrumb builders give it.
 *
 * The module builds no trail of its own: it describes the one visitors see,
 * then adds the current page, which breadcrumb builders leave off.
 */
class BreadcrumbTrail {

  /**
   * The trail per page context.
   *
   * @var \WeakMap<\Drupal\neo_seo\Graph\PageContext, array>
   */
  private \WeakMap $trails;

  /**
   * Constructs the trail.
   *
   * @param \Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface $breadcrumb
   *   The breadcrumb manager.
   * @param \Drupal\Core\Routing\RouteMatchInterface $routeMatch
   *   The current route match.
   */
  public function __construct(
    protected BreadcrumbBuilderInterface $breadcrumb,
    protected RouteMatchInterface $routeMatch,
  ) {
    $this->trails = new \WeakMap();
  }

  /**
   * The trail: home first, the page last; empty on the front page.
   *
   * @return array<int, array{name: string, url: string}>
   *   The items.
   */
  public function items(PageContext $context): array {
    return $this->trails[$context] ??= $this->build($context);
  }

  /**
   * Builds the trail.
   */
  protected function build(PageContext $context): array {
    if ($context->isFront) {
      return [];
    }
    $breadcrumb = $this->breadcrumb->build($this->routeMatch);
    $context->addCacheableDependency($breadcrumb);
    $items = [];
    foreach ($breadcrumb->getLinks() as $link) {
      $name = trim(strip_tags((string) (is_array($link->getText()) ? '' : $link->getText())));
      $url = $link->getUrl();
      if ($name === '' || !$url->isRouted() && !$url->isExternal()) {
        continue;
      }
      $generated = $url->setAbsolute()->toString(TRUE);
      $context->addCacheableDependency($generated);
      $items[] = ['name' => $name, 'url' => $generated->getGeneratedUrl()];
    }
    $last = end($items);
    if (!$last || $last['url'] !== $context->canonicalUrl) {
      $items[] = ['name' => (string) $context->title, 'url' => $context->canonicalUrl];
    }
    return array_values(array_filter($items, static fn(array $item): bool => $item['name'] !== ''));
  }

}
