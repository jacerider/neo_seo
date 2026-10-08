<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Hook;

use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\Order;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\Core\Render\Markup;
use Drupal\neo_seo\Graph\GraphBuilder;
use Drupal\neo_seo\Graph\PageContextFactory;
use Drupal\neo_seo\Head\HeadGate;
use Drupal\neo_seo\Head\HeadTagPluginManager;
use Drupal\neo_seo\Head\HeadValuesResolver;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Puts the page's schema graph and head tags on it.
 */
class NeoSeoHooks {

  /**
   * Constructs the hooks.
   *
   * @param \Drupal\neo_seo\Graph\PageContextFactory $pageContextFactory
   *   The page context factory.
   * @param \Drupal\neo_seo\Graph\GraphBuilder $graphBuilder
   *   The graph builder.
   * @param \Drupal\neo_seo\Head\HeadGate $headGate
   *   The head gate.
   * @param \Drupal\neo_seo\Head\HeadValuesResolver $headValues
   *   The head values resolver.
   * @param \Drupal\neo_seo\Head\HeadTagPluginManager $headTagManager
   *   The head tag plugin manager.
   * @param \Drupal\Core\PageCache\ResponsePolicy\KillSwitch $pageCacheKillSwitch
   *   The page cache kill switch, so a diff render is never cached.
   */
  public function __construct(
    protected PageContextFactory $pageContextFactory,
    protected GraphBuilder $graphBuilder,
    protected HeadGate $headGate,
    protected HeadValuesResolver $headValues,
    protected HeadTagPluginManager $headTagManager,
    #[Autowire(service: 'page_cache_kill_switch')]
    protected KillSwitch $pageCacheKillSwitch,
  ) {}

  /**
   * Implements hook_page_attachments().
   *
   * One script holding one @graph, and the head tags, on every page a search
   * engine should see.
   */
  #[Hook('page_attachments')]
  public function pageAttachments(array &$attachments): void {
    if (!$this->headGate->describesPage()) {
      return;
    }
    $context = $this->pageContextFactory->current();
    $head = [];

    if ($this->headGate->ownsHead()) {
      $values = $this->headValues->resolve($context);
      foreach (array_keys($this->headTagManager->getSortedDefinitions()) as $pluginId) {
        /** @var \Drupal\neo_seo\Head\HeadTagInterface $tag */
        $tag = $this->headTagManager->createInstance($pluginId);
        array_push($head, ...$tag->build($values));
      }
    }

    $document = $this->graphBuilder->build($context);
    if ($document['@graph']) {
      $head[] = [
        [
          '#type' => 'html_tag',
          '#tag' => 'script',
          '#attributes' => ['type' => 'application/ld+json', 'class' => ['neo-seo-graph']],
          // JSON_HEX_TAG encodes < and >, so no value in the graph can close
          // the script element early; that is what makes Markup safe here.
          '#value' => Markup::create(json_encode(
            $document,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP,
          )),
        ],
        'neo_seo_graph',
      ];
    }

    $context->getCacheability()->merge($this->headGate->getCacheability())->applyTo($attachments);
    if ($head) {
      $attachments['#attached']['html_head'] = array_merge($attachments['#attached']['html_head'] ?? [], $head);
    }
  }

  /**
   * Implements hook_page_attachments_alter().
   *
   * Drops the Generator meta tag core adds to every page. On a migration diff
   * request it also drops everything metatag and schema_metatag attached, so
   * the page shows only what it will after the switch — which is why this runs
   * last, after schema_metatag has assembled its script.
   */
  #[Hook('page_attachments_alter', order: Order::Last)]
  public function pageAttachmentsAlter(array &$attachments): void {
    if (!$this->headGate->ownsHead()) {
      return;
    }
    $drop = ['system_meta_generator'];
    if ($this->headGate->isDiff()) {
      $drop = [...$drop, 'schema_metatag', ...$this->metatagKeys()];
      $this->pageCacheKillSwitch->trigger();
      $attachments['#cache']['max-age'] = 0;
    }
    // Metatag keys a tag with several values by delta: og_image_0, og_image_1.
    $pattern = '/^(' . implode('|', array_map('preg_quote', $drop)) . ')(_\d+)?$/';
    foreach ($attachments['#attached']['html_head'] ?? [] as $key => $value) {
      if (preg_match($pattern, (string) ($value[1] ?? ''))) {
        unset($attachments['#attached']['html_head'][$key]);
      }
    }
  }

  /**
   * The html_head keys metatag attaches its tags under: its tag plugin ids.
   *
   * @return string[]
   *   The keys, empty without metatag.
   */
  protected function metatagKeys(): array {
    $container = \Drupal::getContainer();
    if (!$container->has('plugin.manager.metatag.tag')) {
      return [];
    }
    return array_keys($container->get('plugin.manager.metatag.tag')->getDefinitions());
  }

  /**
   * Implements hook_entity_view_alter().
   *
   * The entity view controller attaches its own canonical and shortlink. The
   * canonical would duplicate neo_seo's, and the shortlink publishes the
   * unaliased path; both go from the page's own entity.
   */
  #[Hook('entity_view_alter')]
  public function entityViewAlter(array &$build, EntityInterface $entity, EntityViewDisplayInterface $display): void {
    if (empty($build['#attached']['html_head_link']) || !$this->headGate->ownsHead()) {
      return;
    }
    $pageEntity = $this->pageContextFactory->current()->entity;
    if (!$pageEntity || $pageEntity->getEntityTypeId() !== $entity->getEntityTypeId() || $pageEntity->id() !== $entity->id()) {
      return;
    }
    foreach ($build['#attached']['html_head_link'] as $key => $link) {
      if (in_array($link[0]['rel'] ?? NULL, ['canonical', 'shortlink'], TRUE)) {
        unset($build['#attached']['html_head_link'][$key]);
      }
    }
  }

  /**
   * Implements hook_preprocess_html().
   *
   * The theme prints the <title>; neo_seo supplies its whole text, so the
   * site name and slogan core appends are not added again.
   */
  #[Hook('preprocess_html')]
  public function preprocessHtml(array &$variables): void {
    if (!$this->headGate->ownsHead()) {
      return;
    }
    $title = $this->headValues->resolve($this->pageContextFactory->current())->get('title');
    if ($title !== '') {
      $variables['head_title'] = ['title' => $title];
    }
  }

}
