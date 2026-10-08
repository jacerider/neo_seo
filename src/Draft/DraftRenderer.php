<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Draft;

use Drupal\Component\Utility\Html;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Theme\ThemeInitializationInterface;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\neo_seo\Graph\BreadcrumbTrail;
use Drupal\neo_seo\Graph\PageContextFactory;
use Drupal\neo_seo\Head\HeadValuesResolver;
use Drupal\neo_seo\SeoOverrides;

/**
 * Renders a page as it will look, before it is saved.
 *
 * The one input to the SEO panel's previews and to content analysis, so both
 * judge the same thing. The entity is rendered in its full view mode in the
 * site's front-end theme, with its title as the page's H1 the way the page
 * shows it, and its head values resolved exactly as the live page resolves
 * them. Nothing is written: the entity is not saved, no draft is touched, and
 * no render or token cache answers for, or remembers, the draft.
 */
class DraftRenderer {

  /**
   * Constructs the renderer.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer.
   * @param \Drupal\Core\Theme\ThemeManagerInterface $themeManager
   *   The theme manager.
   * @param \Drupal\Core\Theme\ThemeInitializationInterface $themeInitialization
   *   The theme initialization service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\neo_seo\Graph\PageContextFactory $pageContextFactory
   *   The page context factory.
   * @param \Drupal\neo_seo\Head\HeadValuesResolver $headValues
   *   The head values resolver.
   * @param \Drupal\neo_seo\Graph\BreadcrumbTrail $breadcrumbTrail
   *   The breadcrumb trail.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   * @param \Drupal\neo_alchemist\EditorState\SharedDraftStore|null $draftStore
   *   The Alchemist shared draft store, when neo_alchemist is installed.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected RendererInterface $renderer,
    protected ThemeManagerInterface $themeManager,
    protected ThemeInitializationInterface $themeInitialization,
    protected ConfigFactoryInterface $configFactory,
    protected PageContextFactory $pageContextFactory,
    protected HeadValuesResolver $headValues,
    protected BreadcrumbTrail $breadcrumbTrail,
    protected ModuleHandlerInterface $moduleHandler,
    protected ?object $draftStore = NULL,
  ) {}

  /**
   * Renders an unsaved entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity, carrying the unsaved values. It is marked as a preview.
   *
   * @return array{html: string, url: string, language: string, head: array, overrides: array}
   *   The rendered body HTML, the page URL and language, the resolved head
   *   values (title, description, social title, robots, canonical, image,
   *   page type, article type), and the overrides the draft carries.
   */
  public function render(ContentEntityInterface $entity): array {
    $entity->in_preview = TRUE;
    $context = $this->pageContextFactory->forEntity($entity);
    $values = $this->headValues->resolve($context);

    $build = $this->entityTypeManager->getViewBuilder($entity->getEntityTypeId())->view($entity, 'full');
    // A draft must neither be served from the render cache nor written to it.
    unset($build['#cache']['keys']);
    $html = $this->inFrontTheme(fn() => (string) $this->renderer->renderInIsolation($build));
    $html = '<h1>' . Html::escape((string) $entity->label()) . '</h1>' . $html;

    $overrides = SeoOverrides::of($entity);
    return [
      'html' => $html,
      'url' => $context->canonicalUrl,
      'language' => $context->langcode,
      'head' => [
        'title' => $values->get('title'),
        'description' => $values->get('description'),
        'social_title' => $values->get('social_title') ?: $values->get('title'),
        'robots' => $values->get('robots'),
        'canonical' => $context->canonicalUrl,
        'image' => $values->image(),
        'page_type' => $values->pageType,
        'article_type' => $values->articleType,
        'site_name' => $context->siteName,
      ],
      'overrides' => [
        'keyphrase' => (string) ($overrides['keyphrase'] ?? ''),
        'cornerstone' => (bool) $overrides['cornerstone'],
      ],
    ];
  }

  /**
   * Applies an entity's Alchemist drafts: layout and SEO panel.
   *
   * The shared layout draft goes into the tree field, and the SEO panel's
   * drafted overrides into the overrides field.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity; changed in memory only.
   */
  public function applyAlchemistDrafts(ContentEntityInterface $entity): void {
    $store = $this->draftStore;
    if (!$store) {
      return;
    }
    foreach ($entity->getFieldDefinitions() as $name => $definition) {
      $item = $definition->getType() === 'neo_component_tree' ? $entity->get($name)->first() : NULL;
      if (!$item || !$store->has($item)) {
        continue;
      }
      if ($store->get($item) !== NULL) {
        // Reads the draft into the item; writes nothing.
        $item->enforceAsDraft();
      }
      $panel = method_exists($store, 'getPanel') ? $store->getPanel($item, 'neo_seo') : NULL;
      if (is_array($panel) && $entity->hasField(SeoOverrides::FIELD)) {
        $entity->set(SeoOverrides::FIELD, $panel + SeoOverrides::of($entity));
      }
    }
  }

  /**
   * A copy of an entity, loaded fresh, that nothing else holds.
   *
   * Drafts are applied to it in memory; the loaded entity in the static cache
   * is left alone so nothing else in the request sees the draft.
   */
  public function freshCopy(ContentEntityInterface $entity): ContentEntityInterface {
    $storage = $this->entityTypeManager->getStorage($entity->getEntityTypeId());
    $copy = $storage->loadUnchanged($entity->id()) ?? $entity;
    $copy = clone $copy;
    return $copy->hasTranslation($entity->language()->getId()) ? $copy->getTranslation($entity->language()->getId()) : $copy;
  }

  /**
   * Runs a callback with the front-end theme active.
   */
  protected function inFrontTheme(callable $callback): mixed {
    $default = (string) $this->configFactory->get('system.theme')->get('default');
    $previous = $this->themeManager->getActiveTheme();
    if ($default === '' || $previous->getName() === $default) {
      return $callback();
    }
    $this->themeManager->setActiveTheme($this->themeInitialization->initTheme($default));
    try {
      return $callback();
    }
    finally {
      $this->themeManager->setActiveTheme($previous);
    }
  }

}
