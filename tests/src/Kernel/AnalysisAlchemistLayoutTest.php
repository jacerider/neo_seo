<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_seo\Kernel;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Form\FormState;
use Drupal\Core\Render\Element;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\field\Entity\FieldConfig;
use Drupal\neo_seo\SeoOverrides;
use Drupal\Tests\neo_alchemist\Kernel\HybridFieldKernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * The edit form's analysis for a page built in its own Alchemist layout.
 *
 * Its checks are acted on in the layout editor, so the form shows the two
 * scores and the way there, and no Readability section. A locked layout is
 * fed by the form's own fields: that page keeps the checks.
 *
 * @see \Drupal\neo_seo_analysis\AlchemistLayout
 */
#[Group('neo_seo')]
class AnalysisAlchemistLayoutTest extends HybridFieldKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'token',
    'neo_icon',
    'neo_tooltip',
    'neo_seo',
    'neo_seo_panel',
    'neo_seo_analysis',
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
    $this->container->get('entity_display.repository')
      ->getViewDisplay('entity_test', 'entity_test')
      ->setComponent(static::FIELD_NAME, ['type' => 'neo_component_tree'])
      ->save();
    $this->setUpCurrentUser([], ['administer entity_test content', 'view test entity', 'edit neo_seo overrides']);
  }

  /**
   * A locked layout: the checks, in the SEO and Readability sections.
   */
  public function testLockedLayoutKeepsTheChecks(): void {
    [$element, $config] = $this->form($this->createTestEntity());
    $this->assertSame(['search_social', 'seo', 'readability'], Element::children($element, TRUE));
    $this->assertArrayNotHasKey('summary', $config['analysis']);
  }

  /**
   * A custom layout: the scores and the editor, opening on its SEO checks.
   */
  public function testCustomLayoutShowsTheScores(): void {
    $this->allowCustom();
    $entity = $this->createTestEntity();
    [$element, $config] = $this->form($entity);

    $this->assertSame(['search_social', 'seo'], Element::children($element, TRUE), 'No Readability section.');
    $this->assertArrayHasKey('analysis_seo', $element['seo'], 'The scores go under the keyphrase.');
    $this->assertStringEndsWith('/entity_test/' . $entity->id() . '/alchemist/alch?neo_seo=seo', $config['analysis']['summary']['editorUrl']);
  }

  /**
   * Without access to the editor: the scores, and no way there.
   */
  public function testNoEditorAccessNoLink(): void {
    $this->allowCustom();
    $entity = $this->createTestEntity();
    $this->setUpCurrentUser([], ['view test entity', 'edit neo_seo overrides']);
    [, $config] = $this->form($entity);
    $this->assertArrayHasKey('summary', $config['analysis']);
    $this->assertNull($config['analysis']['summary']['editorUrl']);
  }

  /**
   * A custom layout the page is not rendered with is not where it is built.
   */
  public function testUndisplayedLayoutKeepsTheChecks(): void {
    $this->allowCustom();
    $this->container->get('entity_display.repository')
      ->getViewDisplay('entity_test', 'entity_test')
      ->removeComponent(static::FIELD_NAME)
      ->save();
    [$element, $config] = $this->form($this->createTestEntity());
    $this->assertContains('readability', Element::children($element));
    $this->assertArrayNotHasKey('summary', $config['analysis']);
  }

  /**
   * Lets each page have a layout of its own.
   */
  protected function allowCustom(): void {
    $field = FieldConfig::loadByName('entity_test', 'entity_test', static::FIELD_NAME);
    $field->setSetting('allow_custom', TRUE)->save();
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
  }

  /**
   * The overrides element of an entity's edit form, and the panel's config.
   *
   * @return array{0: array, 1: array}
   *   The element and the decoded panel config.
   */
  protected function form(EntityTest $entity): array {
    $formObject = $this->container->get('entity_type.manager')->getFormObject('entity_test', 'default')->setEntity($entity);
    $formState = new FormState();
    $form = $this->container->get('form_builder')->buildForm($formObject, $formState);
    $element = $form[SeoOverrides::FIELD]['widget'][0];
    return [$element, Json::decode($element['search_social']['panel']['#attributes']['data-neo-seo-panel'])];
  }

}
