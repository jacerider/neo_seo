<?php

declare(strict_types=1);

namespace Drupal\neo_seo_panel\Plugin\AlchemistPanel;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\AlchemistPanel;
use Drupal\neo_alchemist\Panel\AlchemistPanelBase;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\neo_seo\Mapping\BundleMappingRepository;
use Drupal\neo_seo\SeoOverrides;
use Drupal\neo_seo_panel\Form\SeoPanelForm;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The SEO panel in the Alchemist editor.
 *
 * Its draft key holds SEO overrides keyed by the field's property names — only
 * those the editor changed, plus the analysis scores — so the draft render
 * reads it as "draft over saved" and Publish merges it onto the saved
 * overrides. Revert and Reset discard it with the layout.
 */
#[AlchemistPanel(
  id: 'neo_seo',
  // Not "SEO": the top bar's SEO score is the analysis; this is where the page's
  // search listing and share card are edited.
  label: new TranslatableMarkup('Search & social'),
  icon: 'magnifying-glass',
  weight: 10,
  width: '26rem',
)]
final class SeoPanel extends AlchemistPanelBase implements ContainerFactoryPluginInterface {

  /**
   * The panel's id: its key in the shared draft.
   */
  public const ID = 'neo_seo';

  /**
   * Constructs the panel.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected FormBuilderInterface $formBuilder,
    protected BundleMappingRepository $mapping,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ModuleHandlerInterface $moduleHandler,
    protected ?object $draftStore,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('form_builder'),
      $container->get('neo_seo.bundle_mapping'),
      $container->get('entity_type.manager'),
      $container->get('module_handler'),
      $container->has('neo_alchemist.shared_draft_store') ? $container->get('neo_alchemist.shared_draft_store') : NULL,
    );
  }

  /**
   * {@inheritdoc}
   *
   * Only on an entity with SEO overrides, for an editor who may change them.
   */
  public function access(ComponentTreeItem $item, AccountInterface $account): AccessResultInterface {
    $entity = $item->getEntity();
    if (!$entity instanceof ContentEntityInterface || !$entity->hasField(SeoOverrides::FIELD)) {
      return AccessResult::forbidden('The entity has no SEO overrides.')->addCacheableDependency($entity);
    }
    return parent::access($item, $account)
      ->andIf($entity->get(SeoOverrides::FIELD)->access('edit', $account, TRUE));
  }

  /**
   * {@inheritdoc}
   */
  public function build(ComponentTreeItem $item): array {
    $entity = $item->getEntity();
    if (!$entity instanceof ContentEntityInterface) {
      return [];
    }
    $draft = $this->draftStore?->getPanel($item, self::ID);
    $draft = is_array($draft) ? $this->clean($entity, $draft) : NULL;
    return $this->formBuilder->getForm(SeoPanelForm::class, $entity, $draft);
  }

  /**
   * {@inheritdoc}
   *
   * What other modules put there (hook_neo_seo_panel_badge_alter()):
   * neo_seo_analysis adds the page's scores. Nothing, no badge.
   */
  public function badge(ComponentTreeItem $item): array {
    $entity = $item->getEntity();
    $badge = [];
    if ($entity instanceof ContentEntityInterface) {
      $this->moduleHandler->alter('neo_seo_panel_badge', $badge, $entity);
    }
    return $badge;
  }

  /**
   * {@inheritdoc}
   */
  public function publish(ComponentTreeItem $item, ContentEntityInterface $entity, mixed $data): void {
    if (!is_array($data) || !$entity->hasField(SeoOverrides::FIELD)) {
      return;
    }
    $clean = $this->clean($entity, $data);
    if ($clean) {
      $entity->set(SeoOverrides::FIELD, $clean + SeoOverrides::of($entity));
    }
  }

  /**
   * Drafted overrides reduced to what may be stored on the entity.
   *
   * The data came from the browser: unknown keys, types the bundle does not
   * allow, out-of-range scores and a share image that is not a media item are
   * dropped, so the saved value stands for them.
   */
  protected function clean(ContentEntityInterface $entity, array $data): array {
    $clean = SeoOverrides::clean($data, $this->mapping->forBundle($entity->getEntityTypeId(), $entity->bundle()));
    if (!empty($clean['image']) && (!$this->entityTypeManager->hasDefinition('media') || !$this->entityTypeManager->getStorage('media')->load($clean['image']))) {
      unset($clean['image']);
    }
    return $clean;
  }

}
