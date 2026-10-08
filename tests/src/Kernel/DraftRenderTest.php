<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_seo\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\neo_seo\Entity\SeoDefaults;
use Drupal\neo_seo\Hook\DraftRenderHooks;
use Drupal\neo_seo\SeoOverrides;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * The draft render: the page as it will look, before it is saved.
 */
#[Group('neo_seo')]
class DraftRenderTest extends NeoSeoKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node', 'text', 'filter'];

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
    (SeoDefaults::load('global') ?? SeoDefaults::create(['id' => 'global', 'label' => 'Global']))
      ->setValues(['title' => '[node:title] | [site:name]', 'description' => 'Template'])
      ->save();
    $this->setUpCurrentUser([], ['access content', 'bypass node access', 'edit neo_seo overrides']);
  }

  /**
   * An unsaved change shows in the render, and nothing is written.
   */
  public function testUnsavedChangesRenderWithoutBeingSaved(): void {
    $node = Node::create(['type' => 'page', 'title' => 'Saved title']);
    $node->save();

    $draft = $this->container->get('neo_seo.draft_renderer')->freshCopy($node);
    $draft->setTitle('Draft title');
    $draft->set(SeoOverrides::FIELD, [
      'description' => 'Draft description',
      'keyphrase' => 'gardens',
      'cornerstone' => TRUE,
    ]);
    $render = $this->container->get('neo_seo.draft_renderer')->render($draft);

    $this->assertStringStartsWith('<h1>Draft title</h1>', $render['html']);
    $this->assertSame('Draft title | Acme', $render['head']['title']);
    $this->assertSame('Draft description', $render['head']['description']);
    $this->assertSame(['keyphrase' => 'gardens', 'cornerstone' => TRUE], $render['overrides']);
    $this->assertStringEndsWith('/node/' . $node->id(), $render['url']);

    $saved = Node::load($node->id());
    $this->assertSame('Saved title', $saved->label(), 'The entity is not saved.');
    $this->assertNull(SeoOverrides::of($saved)['description']);
    $this->assertSame('Saved title', $node->label(), 'The loaded entity is not changed either.');
  }

  /**
   * The edit form's hidden button renders the form's values, valid or not.
   */
  public function testEditFormRendersItsValues(): void {
    $node = Node::create(['type' => 'page', 'title' => 'Saved title']);
    $node->save();

    $formObject = $this->container->get('entity_type.manager')->getFormObject('node', 'default')->setEntity($node);
    $formState = new FormState();
    $form = $this->container->get('form_builder')->buildForm($formObject, $formState);
    $this->assertArrayHasKey(DraftRenderHooks::BUTTON, $form['actions'], 'The form carries the draft render button.');
    $this->assertSame([], $form['actions'][DraftRenderHooks::BUTTON]['#submit']);

    $formState->setValue('title', [['value' => 'Typed title']]);
    $formState->setValue(SeoOverrides::FIELD, [
      [
        'title' => 'Typed SEO title',
        'description' => '',
        'image' => NULL,
        'keyphrase' => 'typed',
        'page_type' => '',
        'article_type' => '',
        'noindex' => 0,
        'cornerstone' => 0,
      ],
    ]);
    $formState->setErrorByName('title', 'An error the button ignores.');
    $response = DraftRenderHooks::ajaxRender($form, $formState);
    $command = $response->getCommands()[0];

    $this->assertSame('neoSeoDraftRender', $command['command']);
    $this->assertStringStartsWith('<h1>Typed title</h1>', $command['render']['html']);
    $this->assertSame('Typed SEO title', $command['render']['head']['title']);
    $this->assertSame('typed', $command['render']['overrides']['keyphrase']);
    $this->assertSame('Saved title', Node::load($node->id())->label());
  }

  /**
   * Values stored outside a field's own key still reach the draft.
   *
   * The term form keeps its parent select inside a details but stores it at
   * ['parent'], and builds the entity from there.
   */
  public function testInputsOutsideFieldKeysKeepTheirValues(): void {
    $form = [
      '#type' => 'form',
      'title' => ['#input' => TRUE, '#parents' => ['title']],
      'relations' => [
        '#type' => 'details',
        'parent' => ['#input' => TRUE, '#parents' => ['parent']],
        'weight' => ['#input' => TRUE, '#parents' => ['weight']],
      ],
      'actions' => [
        DraftRenderHooks::BUTTON => ['#input' => TRUE, '#parents' => [DraftRenderHooks::BUTTON]],
      ],
    ];
    $formState = new FormState();
    $formState->setTriggeringElement([
      '#name' => DraftRenderHooks::BUTTON,
      '#limit_validation_errors' => [['title']],
    ]);

    DraftRenderHooks::afterBuild($form, $formState);

    $sections = $formState->getTriggeringElement()['#limit_validation_errors'];
    $this->assertContains(['parent'], $sections);
    $this->assertContains(['weight'], $sections);
    $this->assertContains(['title'], $sections);
    $this->assertCount(1, array_keys($sections, ['title']), 'A section is listed once.');

    // Any other button is left alone.
    $other = new FormState();
    $other->setTriggeringElement(['#name' => 'op', '#limit_validation_errors' => FALSE]);
    DraftRenderHooks::afterBuild($form, $other);
    $this->assertFalse($other->getTriggeringElement()['#limit_validation_errors']);
  }

}
