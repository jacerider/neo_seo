<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Render\Markup;
use Drupal\Core\Routing\AdminContext;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\neo_seo\Graph\GraphBuilder;
use Drupal\neo_seo\Graph\PageContextFactory;

/**
 * Puts the page's schema graph in its head.
 */
class NeoSeoHooks {

  /**
   * Constructs the hooks.
   *
   * @param \Drupal\neo_seo\Graph\PageContextFactory $pageContextFactory
   *   The page context factory.
   * @param \Drupal\neo_seo\Graph\GraphBuilder $graphBuilder
   *   The graph builder.
   * @param \Drupal\Core\Routing\AdminContext $adminContext
   *   The admin context.
   * @param \Drupal\Core\Routing\RouteMatchInterface $routeMatch
   *   The current route match.
   */
  public function __construct(
    protected PageContextFactory $pageContextFactory,
    protected GraphBuilder $graphBuilder,
    protected AdminContext $adminContext,
    protected RouteMatchInterface $routeMatch,
  ) {}

  /**
   * Implements hook_page_attachments().
   *
   * One script, one @graph, on every front-end page. Admin pages and the
   * Alchemist editor's own routes describe nothing a search engine should see.
   */
  #[Hook('page_attachments')]
  public function pageAttachments(array &$attachments): void {
    $route = $this->routeMatch->getRouteObject();
    if ($this->adminContext->isAdminRoute($route) || $route?->getOption('_neo_alchemist_editor')) {
      return;
    }
    $context = $this->pageContextFactory->current();
    $document = $this->graphBuilder->build($context);
    $context->getCacheability()->applyTo($attachments);
    if (!$document['@graph']) {
      return;
    }
    $attachments['#attached']['html_head'][] = [
      [
        '#type' => 'html_tag',
        '#tag' => 'script',
        '#attributes' => ['type' => 'application/ld+json'],
        // JSON_HEX_TAG encodes < and >, so no value in the graph can close the
        // script element early; that is what makes the Markup wrapper safe.
        '#value' => Markup::create(json_encode(
          $document,
          JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP,
        )),
      ],
      'neo_seo_graph',
    ];
  }

}
