<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_seo\Kernel;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Form\FormState;
use Drupal\Core\Render\Element;
use Drupal\neo_seo\SeoOverrides;
use Drupal\neo_seo_analysis\Controller\KeyphrasesController;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;

/**
 * Content analysis in the SEO panel: its wiring, keyphrases and list columns.
 */
#[Group('neo_seo')]
class AnalysisPanelTest extends NeoSeoKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node', 'text', 'filter', 'views', 'neo_seo_panel', 'neo_seo_analysis'];

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
    // User 1 is not the editor: it would bypass every access check.
    $this->createUser();
  }

  /**
   * The panel loads analysis, with the keyphrases endpoint for this page.
   */
  public function testPanelCarriesAnalysis(): void {
    $this->setUpCurrentUser([], ['access content', 'bypass node access', 'edit neo_seo overrides']);
    $node = $this->page('About', 'gardens');
    $formObject = $this->container->get('entity_type.manager')->getFormObject('node', 'default')->setEntity($node);
    $formState = new FormState();
    $form = $this->container->get('form_builder')->buildForm($formObject, $formState);

    $element = $form[SeoOverrides::FIELD]['widget'][0];
    $mount = $element['search_social']['panel'];
    $this->assertContains('neo_seo_analysis/panel', $mount['#attached']['library']);
    $config = Json::decode($mount['#attributes']['data-neo-seo-panel']);
    $this->assertStringContainsString('/neo-seo/keyphrases?entity_type=node&exclude=' . $node->id(), $config['analysis']['keyphrasesUrl']);
    $this->assertArrayNotHasKey('summary', $config['analysis'], 'A page without an Alchemist layout gets the checks.');

    // A place for the checks under the keyphrase, and a section of their own
    // for readability's, last.
    $this->assertSame(['search_social', 'seo', 'readability'], Element::children($element, TRUE));
    $this->assertSame('Readability', (string) $element['readability']['#title']);
    $this->assertFalse($element['readability']['#open']);
    $this->assertSame(['keyphrase', 'cornerstone', 'analysis_seo'], Element::children($element['seo'], TRUE));
    $this->assertSame('seo', $element['seo']['analysis_seo']['#attributes']['data-neo-seo-section']);
    $this->assertSame('readability', $element['readability']['analysis_readability']['#attributes']['data-neo-seo-section']);
  }

  /**
   * Other pages' keyphrases: only those the editor may see, never this page's.
   */
  public function testKeyphrasesExcludeThisPageAndUnviewablePages(): void {
    $this->setUpCurrentUser([], ['access content', 'edit neo_seo overrides']);
    $this->page('Own page', 'healing gardens');
    $own = $this->page('Being edited', 'data centers');
    $this->page('Duplicate case', 'Healing Gardens');
    $this->page('Unpublished', 'secret launch', FALSE);
    $this->page('No keyphrase', NULL);

    $request = Request::create('/neo-seo/keyphrases', 'GET', ['entity_type' => 'node', 'exclude' => $own->id()]);
    $response = $this->container->get('class_resolver')->getInstanceFromDefinition(KeyphrasesController::class)->list($request);
    $keyphrases = Json::decode((string) $response->getContent())['keyphrases'];

    $this->assertCount(1, $keyphrases, 'Case variants of one keyphrase count once.');
    $this->assertSame('healing gardens', mb_strtolower($keyphrases[0]));
    $this->assertNotContains('data centers', $keyphrases, 'The page being edited is left out.');
    $this->assertNotContains('secret launch', $keyphrases, 'A page the editor may not see is left out.');
  }

  /**
   * The endpoint takes the overrides permission.
   */
  public function testKeyphrasesNeedThePermission(): void {
    $access = $this->container->get('access_manager');
    $editor = $this->createUser(['access content', 'edit neo_seo overrides']);
    $viewer = $this->createUser(['access content']);
    $this->assertTrue($access->checkNamedRoute('neo_seo_analysis.keyphrases', [], $editor));
    $this->assertFalse($access->checkNamedRoute('neo_seo_analysis.keyphrases', [], $viewer));
  }

  /**
   * The content list gets plain handlers for the scores and the flags.
   */
  public function testViewsData(): void {
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
    $this->container->get('views.views_data')->clear();
    $data = $this->container->get('views.views_data')->get('node_field_data');
    $this->assertSame('neo_seo_score', $data['neo_seo__seo_score']['field']['id']);
    $this->assertSame('neo_seo_score', $data['neo_seo__readability_score']['field']['id']);
    $this->assertSame('numeric', $data['neo_seo__seo_score']['filter']['id']);
    $this->assertSame('boolean', $data['neo_seo__cornerstone']['filter']['id']);
    $this->assertTrue($data['neo_seo__cornerstone']['filter']['accept null'], 'A page with no overrides counts as not cornerstone.');
    $this->assertSame('SEO score', (string) $data['neo_seo__seo_score']['title']);
  }

  /**
   * Creates a page with a focus keyphrase.
   */
  protected function page(string $title, ?string $keyphrase, bool $published = TRUE): Node {
    $node = Node::create([
      'type' => 'page',
      'title' => $title,
      'status' => $published,
      'uid' => 1,
      SeoOverrides::FIELD => $keyphrase === NULL ? [] : ['keyphrase' => $keyphrase],
    ]);
    $node->save();
    return $node;
  }

}
