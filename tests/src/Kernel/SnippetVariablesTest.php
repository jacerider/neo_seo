<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_seo\Kernel;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Form\FormState;
use Drupal\neo_seo\Draft\DraftRenderer;
use Drupal\neo_seo\Entity\SeoDefaults;
use Drupal\neo_seo\SeoOverrides;
use Drupal\neo_seo\Snippet;
use Drupal\neo_seo\TokenText;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;

/**
 * Snippet variables: the separator, the variables, and the pill input's data.
 *
 * @see \Drupal\neo_seo\Snippet
 * @see \Drupal\neo_seo\Controller\TokensController
 */
#[Group('neo_seo')]
class SnippetVariablesTest extends NeoSeoKernelTestBase {

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
    (SeoDefaults::load('global') ?? SeoDefaults::create(['id' => 'global', 'label' => 'Global']))->setValues([
      // [node:*], not [neo:*]: the neo module is not enabled here.
      'title' => '[node:title] [neo-seo:separator] [site:name]',
      'description' => '[node:title] at [site:name]',
    ])->save();
    // User 1 is not the editor: it would bypass every access check.
    $this->createUser();
    $this->setUpCurrentUser([], ['access content', 'bypass node access', 'edit neo_seo overrides']);
  }

  /**
   * The separator token: the setting, with or without a page.
   */
  public function testSeparator(): void {
    $token = $this->container->get('token');
    $this->assertSame('|', $token->replacePlain('[neo-seo:separator]'));
    $this->config('neo_seo.settings')->set('separator', '•')->save();
    $this->assertSame('•', $token->replacePlain('[neo-seo:separator]'));
    $this->assertSame('About • Acme', $this->head($this->page('About'))['title']);
    $this->assertSame('Acme', TokenText::tidy('• Acme'), 'A stranded separator goes, whichever is chosen.');
  }

  /**
   * The update moves the shipped title template onto the token, and only it.
   */
  public function testUpdate(): void {
    $this->container->get('module_handler')->loadInclude('neo_seo', 'install');
    $settings = $this->config('neo_seo.settings');
    $settings->clear('separator')->clear('variables')->save();
    SeoDefaults::load('global')->setValues(['title' => '[neo:title] | [site:name]'])->save();
    neo_seo_update_11003();
    $this->assertSame('|', $this->config('neo_seo.settings')->get('separator'));
    $this->assertSame('[neo:title]', $this->config('neo_seo.settings')->get('variables')[0]['token']);
    $this->assertSame('[neo:title] [neo-seo:separator] [site:name]', $this->container->get('config.factory')->get('neo_seo.defaults.global')->get('values.title'));

    SeoDefaults::load('global')->setValues(['title' => '[neo:title] — [site:name]'])->save();
    neo_seo_update_11003();
    $this->assertSame('[neo:title] — [site:name]', $this->container->get('config.factory')->get('neo_seo.defaults.global')->get('values.title'), 'An edited template is left alone.');
  }

  /**
   * The widget's title and description carry the input and their templates.
   */
  public function testWidgetFields(): void {
    $form = $this->form($this->page('About'));
    $element = $form[SeoOverrides::FIELD]['widget'][0];
    $title = $this->findElement($element, 'title');
    $config = Json::decode($title['#attributes']['data-neo-seo-snippet']);
    $this->assertSame(['node'], $config['types']);
    $this->assertSame('[node:title] [neo-seo:separator] [site:name]', $config['template']);
    $this->assertContains('neo_seo/snippet', $title['#attached']['library']);
    $variables = $title['#attached']['drupalSettings']['neoSeoSnippet']['variables'];
    $this->assertSame(['label' => 'Title', 'token' => '[neo:title]'], $variables[0]);
    $this->assertStringEndsWith('/neo-seo/tokens', $title['#attached']['drupalSettings']['neoSeoSnippet']['tokensUrl']);
    $this->assertSame('[node:title] at [site:name]', Json::decode($this->findElement($element, 'description')['#attributes']['data-neo-seo-snippet'])['template']);
  }

  /**
   * A title equal to its template is no override.
   */
  public function testTemplateIsNoOverride(): void {
    $this->assertNull(Snippet::override('[neo:title]  [neo-seo:separator] [site:name] ', '[neo:title] [neo-seo:separator] [site:name]'));
    $this->assertNull(Snippet::override('  ', '[neo:title]'));
    $this->assertSame('Guide: [neo:title]', Snippet::override('Guide: [neo:title] ', '[neo:title]'));

    $node = $this->page('About');
    $formObject = $this->container->get('entity_type.manager')->getFormObject('node', 'default')->setEntity($node);
    $formState = new FormState();
    $form = $this->container->get('form_builder')->buildForm($formObject, $formState);
    $widget = $this->container->get('plugin.manager.field.widget')->getInstance([
      'field_definition' => $node->get(SeoOverrides::FIELD)->getFieldDefinition(),
      'form_mode' => 'default',
      'configuration' => ['type' => 'neo_seo_overrides', 'settings' => []],
    ]);
    $values = $widget->massageFormValues([
      ['title' => '[node:title] [neo-seo:separator] [site:name]', 'description' => 'Our story'],
    ], $form, $formState);
    $this->assertNull($values[0]['title'], 'The template, unchanged, saves nothing.');
    $this->assertSame('Our story', $values[0]['description']);
  }

  /**
   * A draft render says what each token in the title and description gives.
   */
  public function testDraftRenderTokens(): void {
    $node = $this->page('About');
    $node->set(SeoOverrides::FIELD, ['description' => 'Visit [site:name]']);
    $render = $this->container->get(DraftRenderer::class)->render($node);
    $tokens = (array) $render['tokens'];
    $this->assertSame('About', $tokens['[node:title]']);
    $this->assertSame('|', $tokens['[neo-seo:separator]'], 'Not tidied away: it is the separator.');
    $this->assertSame('Acme', $tokens['[site:name]'], 'From the override as well as the templates.');
  }

  /**
   * Tokens searched, named and checked.
   */
  public function testTokensEndpoint(): void {
    $found = $this->get('/neo-seo/tokens?types[]=node&q=title');
    $this->assertContains('[node:title]', array_column($found['tokens'], 'token'));

    $checked = $this->get('/neo-seo/tokens?types[]=node&check[]=[node:title]&check[]=[node:nope]&check[]=[neo-seo:separator]');
    $this->assertSame('Title', $checked['names']['[node:title]']);
    $this->assertSame('Separator', $checked['names']['[neo-seo:separator]']);
    $this->assertSame(['[node:nope]'], $checked['invalid']);

    $this->setUpCurrentUser([], ['access content']);
    $response = $this->container->get('http_kernel')->handle(Request::create('/neo-seo/tokens?q=title'));
    $this->assertSame(403, $response->getStatusCode());
  }

  /**
   * A saved page.
   */
  protected function page(string $title): NodeInterface {
    $node = Node::create(['type' => 'page', 'title' => $title]);
    $node->save();
    return $node;
  }

  /**
   * A page's resolved head values.
   */
  protected function head(NodeInterface $node): array {
    return $this->container->get(DraftRenderer::class)->render($node)['head'];
  }

  /**
   * The node form, built.
   */
  protected function form(NodeInterface $node): array {
    $formObject = $this->container->get('entity_type.manager')->getFormObject('node', 'default')->setEntity($node);
    return $this->container->get('form_builder')->getForm($formObject);
  }

  /**
   * An element by key anywhere under another, as sections move them.
   */
  protected function findElement(array $element, string $key): ?array {
    if (isset($element[$key]) && is_array($element[$key])) {
      return $element[$key];
    }
    foreach ($element as $name => $child) {
      if (is_array($child) && !str_starts_with((string) $name, '#') && ($found = $this->findElement($child, $key))) {
        return $found;
      }
    }
    return NULL;
  }

  /**
   * A JSON response's body.
   */
  protected function get(string $uri): array {
    $response = $this->container->get('http_kernel')->handle(Request::create($uri));
    $this->assertSame(200, $response->getStatusCode(), $uri);
    return Json::decode((string) $response->getContent());
  }

}
