<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\neo_seo\Draft\DraftRenderer;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Serves an entity's draft render: its Alchemist drafts applied, not saved.
 */
class DraftRenderController implements ContainerInjectionInterface {

  use AutowireTrait;

  /**
   * Constructs the controller.
   *
   * @param \Drupal\neo_seo\Draft\DraftRenderer $draftRenderer
   *   The draft renderer.
   */
  public function __construct(
    protected DraftRenderer $draftRenderer,
  ) {}

  /**
   * The draft render of the route's entity, as JSON.
   */
  public function render(RouteMatchInterface $route_match): JsonResponse {
    $entity = $route_match->getParameter('entity');
    if (!$entity instanceof ContentEntityInterface) {
      return new JsonResponse(['message' => 'Not found.'], 404);
    }
    // A fresh copy: the draft is applied in memory and never saved.
    $draft = $this->draftRenderer->freshCopy($entity);
    $this->draftRenderer->applyAlchemistDrafts($draft);
    // Alchemist's render: its URL changes go live on Publish.
    $response = new CacheableJsonResponse($this->draftRenderer->render($draft, TRUE));
    $response->addCacheableDependency((new CacheableMetadata())->setCacheMaxAge(0));
    return $response;
  }

}
