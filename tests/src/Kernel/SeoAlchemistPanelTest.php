<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_seo\Kernel;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Render\RenderContext;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\neo_alchemist\ComponentManageHelper;
use Drupal\neo_alchemist\EditorState\SharedDraftStore;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\neo_seo\SeoOverrides;
use Drupal\neo_seo_panel\Plugin\AlchemistPanel\SeoPanel;
use Drupal\Tests\neo_alchemist\Kernel\HybridFieldKernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * The SEO panel in the Alchemist editor: drafted, then published or reverted.
 */
#[Group('neo_seo')]
class SeoAlchemistPanelTest extends HybridFieldKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'token',
    'neo_seo',
    'neo_seo_panel',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'neo_seo']);
    $this->installSchema('system', ['sequences']);
    $this->config('neo_seo.settings')->set('override_entity_types', ['entity_test'])->save();
    $this->container->get('entity_type.manager')->clearCachedDefinitions();
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
    $this->container->get('router.builder')->rebuild();
    $this->setUpCurrentUser([], ['administer entity_test content', 'view test entity', 'edit neo_seo overrides']);
  }

  /**
   * The panel is the editor's who may change the overrides, and only theirs.
   */
  public function testAccess(): void {
    $item = $this->item($this->createTestEntity());
    $this->assertTrue($this->panel()->access($item, $this->container->get('current_user'))->isAllowed());

    $noPermission = $this->createUser(['administer entity_test content', 'view test entity']);
    $this->assertFalse($this->panel()->access($item, $noPermission)->isAllowed(), 'Updating the entity is not enough.');

    $noUpdate = $this->createUser(['view test entity', 'edit neo_seo overrides']);
    $this->assertFalse($this->panel()->access($item, $noUpdate)->isAllowed(), 'The permission alone is not enough.');

    $this->assertContains(SeoPanel::ID, array_keys($this->manager()->getDefinitions()));
  }

  /**
   * The panel body is the overrides form, showing the draft, for Alchemist.
   */
  public function testBuildShowsTheDraft(): void {
    $entity = $this->createTestEntity();
    $entity->set(SeoOverrides::FIELD, ['title' => 'Saved title', 'description' => 'Saved description'])->save();
    $item = $this->item($this->reloadEntity($entity));
    $this->store()->setPanel($item, SeoPanel::ID, ['title' => 'Drafted title']);

    $build = $this->container->get('renderer')->executeInRenderContext(new RenderContext(), fn() => $this->panel()->build($item));

    $element = $build[SeoOverrides::FIELD][0];
    $this->assertSame('Drafted title', $element['title']['#default_value'], 'The draft shows.');
    $this->assertSame('Saved description', $element['description']['#default_value'], 'What the draft leaves alone shows as saved.');
    $config = Json::decode($element['panel']['#attributes']['data-neo-seo-panel']);
    $this->assertSame('alchemist', $config['host']);
    $this->assertStringEndsWith('/neo-seo/draft/entity_test/' . $entity->id(), $config['renderUrl']);
    $this->assertContains('neo_seo_panel/alchemist', $element['panel']['#attached']['library']);
  }

  /**
   * Publish merges the drafted overrides onto the saved ones.
   */
  public function testPublishMergesTheDraftOntoSavedOverrides(): void {
    $entity = $this->createTestEntity();
    $entity->set(SeoOverrides::FIELD, ['title' => 'Saved title', 'description' => 'Saved description'])->save();
    $item = $this->item($this->reloadEntity($entity));
    $this->store()->setPanel($item, SeoPanel::ID, [
      'title' => '  Drafted title  ',
      'noindex' => TRUE,
      'seo_score' => 35,
      'readability_score' => 150,
      'page_type' => 'AboutPage',
      'image' => 999,
      'unknown' => 'dropped',
    ]);

    $this->publish($item);

    $saved = SeoOverrides::of($this->reloadEntity($entity));
    $this->assertSame('Drafted title', $saved['title'], 'The drafted title is published, trimmed.');
    $this->assertSame('Saved description', $saved['description'], 'An override the draft did not touch is kept.');
    $this->assertTrue((bool) $saved['noindex']);
    $this->assertSame(35, (int) $saved['seo_score'], 'A score is published with the overrides.');
    $this->assertNull($saved['readability_score'], 'An out-of-range score is dropped.');
    $this->assertNull($saved['page_type'], 'A page type the bundle does not allow is dropped.');
    $this->assertNull($saved['image'], 'A share image that is not a media item is dropped.');
    $this->assertFalse($this->store()->has($this->item($this->reloadEntity($entity))), 'Publish consumed the draft.');
  }

  /**
   * Until Publish, the entity keeps its saved overrides; Revert discards.
   */
  public function testDraftIsNotLiveAndRevertDiscardsIt(): void {
    $entity = $this->createTestEntity();
    $entity->set(SeoOverrides::FIELD, ['title' => 'Saved title'])->save();
    $item = $this->item($this->reloadEntity($entity));
    $this->store()->setPanel($item, SeoPanel::ID, ['title' => 'Drafted title']);

    $this->assertSame('Saved title', SeoOverrides::of($this->reloadEntity($entity))['title'], 'A drafted title is not live.');
    $render = $this->container->get('neo_seo.draft_renderer');
    $draft = $render->freshCopy($entity);
    $render->applyAlchemistDrafts($draft);
    $this->assertSame('Drafted title', SeoOverrides::of($draft)['title'], 'The draft render reads the drafted title.');

    // Revert deletes the draft record.
    $this->store()->delete($item);
    $this->assertNull($this->store()->getPanel($item, SeoPanel::ID));
    $this->assertSame('Saved title', SeoOverrides::of($this->reloadEntity($entity))['title']);
  }

  /**
   * The top bar badge holds analysis's score pills, for panel editors only.
   */
  public function testBadgeShowsScoresOnlyWithAnalysis(): void {
    $item = $this->item($this->createTestEntity());
    $this->assertSame([], $this->panel()->badge($item), 'No analysis, no badge.');

    $this->enableModules(['neo_seo_analysis']);
    $badge = $this->panel()->badge($item);
    $this->assertContains('btn-group', $badge['neo_seo_analysis']['#attributes']['class'], 'The pills are a button group like the scale and device groups.');
    $this->assertSame('seo', $badge['neo_seo_analysis']['seo']['#attributes']['data-neo-seo-score']);
    $this->assertSame('readability', $badge['neo_seo_analysis']['readability']['#attributes']['data-neo-seo-score']);
    $this->assertContains('is-active:btn-primary', $badge['neo_seo_analysis']['seo']['#attributes']['class']);
    $this->assertContains('neo_seo_analysis/panel', $badge['#attached']['library']);
    $badges = $this->container->get('renderer')->executeInRenderContext(new RenderContext(), static fn() => ComponentManageHelper::buildSidePanels($item))['badges'];
    $this->assertArrayHasKey('badge_' . SeoPanel::ID, $badges);

    $this->setCurrentUser($this->createUser(['administer entity_test content', 'view test entity']));
    $badges = $this->container->get('renderer')->executeInRenderContext(new RenderContext(), static fn() => ComponentManageHelper::buildSidePanels($item))['badges'];
    $this->assertSame([], $badges, 'An editor who may not open the panel gets no badge.');
  }

  /**
   * The Save confirmation gets a hidden place for the SEO summary.
   *
   * Only with analysis, and only for an editor who may see the SEO panel.
   */
  public function testSaveConfirmationSummaryMount(): void {
    $item = $this->item($this->createTestEntity());
    $this->assertArrayNotHasKey('neo_seo_summary', $this->publishForm($item), 'No analysis, no summary.');

    $this->enableModules(['neo_seo_analysis']);
    $form = $this->publishForm($item);
    $this->assertArrayHasKey('neo_seo_summary', $form);
    $mount = $form['neo_seo_summary'];
    $this->assertSame('hidden', $mount['#attributes']['hidden'], 'Hidden until the editor fills it.');
    $this->assertArrayHasKey('data-neo-seo-save-summary', $mount['#attributes']);
    $this->assertContains('neo_seo_analysis/panel', $mount['#attached']['library']);
    $this->assertSame('html_tag', $mount['#type'], 'Markup, not an input: nothing is submitted.');
    $this->assertArrayHasKey('submit', $form['actions'], 'The confirmation keeps its own actions.');

    $this->setCurrentUser($this->createUser(['administer entity_test content', 'view test entity']));
    $this->assertArrayNotHasKey('neo_seo_summary', $this->publishForm($item), 'Not for an editor who cannot see SEO.');
  }

  /**
   * The Alchemist Save confirmation, as its route builds it.
   */
  protected function publishForm(ComponentTreeItem $item): array {
    return $this->container->get('entity.form_builder')->getForm($item->getEntity(), 'alchemist_publish', ['fieldItem' => $item]);
  }

  /**
   * Publishes the way the publish form does.
   */
  protected function publish(ComponentTreeItem $item): void {
    $item->enforceAsDraft(FALSE)->carryDraftVersion($this->store()->version($item))->saveComponents();
  }

  /**
   * The entity's tree item, in draft mode as the editor routes load it.
   */
  protected function item(EntityTest $entity): ComponentTreeItem {
    $item = $entity->get(static::FIELD_NAME)->first();
    $this->assertInstanceOf(ComponentTreeItem::class, $item);
    return $item->enforceAsDraft();
  }

  /**
   * The SEO panel plugin.
   */
  protected function panel(): SeoPanel {
    $panel = $this->manager()->createInstance(SeoPanel::ID);
    $this->assertInstanceOf(SeoPanel::class, $panel);
    return $panel;
  }

  /**
   * The panel manager.
   */
  protected function manager(): object {
    return $this->container->get('plugin.manager.neo_alchemist_panel');
  }

  /**
   * The shared draft store.
   */
  protected function store(): SharedDraftStore {
    return $this->container->get('neo_alchemist.shared_draft_store');
  }

}
