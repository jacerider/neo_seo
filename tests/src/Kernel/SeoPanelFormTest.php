<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_seo\Kernel;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Form\FormState;
use Drupal\neo_seo\SeoOverrides;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * The SEO panel in the edit form, and the scores it stores.
 */
#[Group('neo_seo')]
class SeoPanelFormTest extends NeoSeoKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node', 'text', 'filter', 'neo_seo_panel'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('system', ['sequences']);
    $this->installConfig(['node', 'filter']);
    $this->config('neo_seo.settings')->set('override_entity_types', ['node'])->save();
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    $this->setUpCurrentUser([], ['access content', 'bypass node access', 'edit neo_seo overrides']);
  }

  /**
   * The edit form's SEO element carries the panel; the scores are inputs.
   */
  public function testPanelMountsOnTheWidget(): void {
    $node = Node::create(['type' => 'page', 'title' => 'About']);
    $node->save();
    $form = $this->form($node);

    $element = $form[SeoOverrides::FIELD]['widget'][0];
    $this->assertArrayHasKey('panel', $element, 'The panel mounts inside the widget.');
    $config = Json::decode($element['panel']['#attributes']['data-neo-seo-panel']);
    $this->assertSame('form', $config['host']);
    $this->assertSame('node', $config['entityType']);
    $this->assertSame((string) $node->id(), $config['entityId']);
    $this->assertStringEndsWith('/neo-seo/draft/node/' . $node->id(), $config['renderUrl']);
    $this->assertContains('neo_seo_panel/panel', $element['panel']['#attached']['library']);
    $this->assertNotContains('neo_seo_panel/alchemist', $element['panel']['#attached']['library'], 'The edit form does not load the Alchemist registration.');
    // The real inputs stay, so the form saves without the panel's help.
    $this->assertSame('textfield', $element['title']['#type']);
    foreach (['seo_score', 'readability_score'] as $score) {
      $this->assertSame('hidden', $element[$score]['#type'], "The $score is an input the panel can set.");
    }
  }

  /**
   * Every SEO field carries its help as a description, like the others.
   *
   * The hint first, then the explanation: shown wherever the site shows
   * descriptions (behind a "?" badge with neo_tooltip), never as markup of
   * the widget's own.
   */
  public function testFieldsCarryHelp(): void {
    $node = Node::create(['type' => 'page', 'title' => 'About']);
    $node->save();
    $element = $this->form($node)[SeoOverrides::FIELD]['widget'][0];
    foreach (['keyphrase', 'cornerstone', 'page_type', 'article_type', 'noindex'] as $key) {
      $description = (string) ($element[$key]['#description'] ?? '');
      $this->assertMatchesRegularExpression('#^<strong>[^<]+</strong> \S#', $description, "$key has a hint, then an explanation.");
      $this->assertArrayNotHasKey('#suffix', $element[$key], "$key has no help markup of its own.");
    }
    $this->assertStringStartsWith('<strong>Ask search engines not to list this page.</strong> For pages', (string) $element['noindex']['#description']);
    $this->assertStringContainsString('characters', (string) $element['title']['#description'], 'Fields that already had a description keep it.');
  }

  /**
   * A new entity has no draft endpoint yet; the panel renders through the form.
   */
  public function testNewEntityHasNoRenderUrl(): void {
    $form = $this->form(Node::create(['type' => 'page']));
    $config = Json::decode($form[SeoOverrides::FIELD]['widget'][0]['panel']['#attributes']['data-neo-seo-panel']);
    $this->assertNull($config['entityId']);
    $this->assertNull($config['renderUrl']);
  }

  /**
   * Scores from the form are stored when valid and dropped when not.
   */
  public function testScoresFromTheFormAreAdvisory(): void {
    $node = Node::create(['type' => 'page', 'title' => 'About']);
    $node->save();
    $formObject = $this->container->get('entity_type.manager')->getFormObject('node', 'default')->setEntity($node);
    $formState = new FormState();
    $form = $this->container->get('form_builder')->buildForm($formObject, $formState);
    $widget = $node->get(SeoOverrides::FIELD)->getFieldDefinition();
    $instance = $this->container->get('plugin.manager.field.widget')->getInstance([
      'field_definition' => $widget,
      'form_mode' => 'default',
      'configuration' => ['type' => 'neo_seo_overrides', 'settings' => []],
    ]);
    $values = $instance->massageFormValues([
      ['title' => '', 'seo_score' => '42', 'readability_score' => '150'],
      ['title' => '', 'seo_score' => 'red', 'readability_score' => '0'],
    ], $form, $formState);

    $this->assertSame(42, $values[0]['seo_score']);
    $this->assertNull($values[0]['readability_score'], 'An out-of-range score is dropped, not an error.');
    $this->assertNull($values[1]['seo_score']);
    $this->assertSame(0, $values[1]['readability_score'], 'Zero is a score.');
  }

  /**
   * The stored scores are 0–100, and a red score saves like any other.
   */
  public function testScoreRangeAndRedScoreSaves(): void {
    $node = Node::create(['type' => 'page', 'title' => 'About']);
    $node->set(SeoOverrides::FIELD, ['title' => 'About us', 'seo_score' => 101]);
    $paths = array_map(static fn($violation) => $violation->getPropertyPath(), iterator_to_array($node->validate()));
    $this->assertContains('neo_seo.0.seo_score', $paths, 'A score above 100 is a violation.');

    $node->set(SeoOverrides::FIELD, ['title' => 'About us', 'seo_score' => 0, 'readability_score' => 12]);
    $paths = array_map(static fn($violation) => $violation->getPropertyPath(), iterator_to_array($node->validate()));
    $this->assertEmpty(array_filter($paths, static fn($path) => str_starts_with($path, 'neo_seo')), 'A red score is valid.');
    $node->save();
    $saved = SeoOverrides::of(Node::load($node->id()));
    $this->assertSame(0, (int) $saved['seo_score']);
    $this->assertSame(12, (int) $saved['readability_score']);
  }

  /**
   * Score parsing: an integer 0–100, NULL for none, FALSE for anything else.
   */
  public function testScoreParsing(): void {
    $this->assertSame(0, SeoOverrides::score('0'));
    $this->assertSame(100, SeoOverrides::score(100));
    $this->assertNull(SeoOverrides::score(''));
    $this->assertNull(SeoOverrides::score(NULL));
    foreach (['101', -1, '4.5', 'abc', 4.5, TRUE, []] as $invalid) {
      $this->assertFalse(SeoOverrides::score($invalid), var_export($invalid, TRUE) . ' is not a score.');
    }
  }

  /**
   * Builds an entity's edit form.
   */
  protected function form(Node $node): array {
    $formObject = $this->container->get('entity_type.manager')->getFormObject('node', 'default')->setEntity($node);
    $formState = new FormState();
    return $this->container->get('form_builder')->buildForm($formObject, $formState);
  }

}
