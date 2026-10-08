<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_seo\Kernel;

use Drupal\neo_seo\Entity\SeoDefaults;
use Drupal\neo_seo\Hook\NeoSeoHooks;
use Drupal\views\Entity\View;
use PHPUnit\Framework\Attributes\Group;

/**
 * Views pages: SEO settings stored in the view, layered above global.
 */
#[Group('neo_seo')]
class ViewsPageTest extends NeoSeoKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['views'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['views']);
    (SeoDefaults::load('global') ?? SeoDefaults::create(['id' => 'global', 'label' => 'Global']))->setValues([
      'title' => 'Global title | [site:name]',
      'description' => 'Global description',
    ])->save();
  }

  /**
   * Creates a page view of users, with SEO settings on its page display.
   */
  protected function createView(array $seo): void {
    View::create([
      'id' => 'people_list',
      'label' => 'People list',
      'base_table' => 'users_field_data',
      'display' => [
        'default' => [
          'id' => 'default',
          'display_plugin' => 'default',
          'position' => 0,
          'display_options' => ['title' => 'Our people'],
        ],
        'page_1' => [
          'id' => 'page_1',
          'display_plugin' => 'page',
          'position' => 1,
          'display_options' => [
            'path' => 'people-list',
            'display_extenders' => ['neo_seo' => ['seo' => $seo]],
          ],
        ],
      ],
    ])->save();
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * The head and graph of the view's page.
   *
   * @return array{0: array, 1: array<string, string|null>, 2: string|null}
   *   The document, the tags and the title.
   */
  protected function page(): array {
    $this->pushRoute('view.people_list.page_1', [], '/people-list');
    $attachments = ['#attached' => []];
    $this->container->get(NeoSeoHooks::class)->pageAttachments($attachments);
    $tags = [];
    $document = [];
    foreach ($attachments['#attached']['html_head'] ?? [] as [$element, $key]) {
      if ($key === 'neo_seo_graph') {
        $document = json_decode((string) $element['#value'], TRUE);
      }
      $tags[$key] = $element['#attributes']['content'] ?? $element['#attributes']['href'] ?? NULL;
    }
    $variables = ['head_title' => []];
    $this->container->get(NeoSeoHooks::class)->preprocessHtml($variables);
    return [$document, $tags, $variables['head_title']['title'] ?? NULL];
  }

  /**
   * A view with settings: its page type and templates, with view tokens.
   */
  public function testSettingsApply(): void {
    $this->createView([
      'title' => '[view:title] at [site:name]',
      'description' => '',
      'image' => '',
      'page_type' => 'CollectionPage',
    ]);
    [$document, $tags, $title] = $this->page();

    $this->assertSame('Our people at Acme', $title);
    $this->assertSame('Global description', $tags['neo_seo_description'], 'An empty setting keeps the global template.');
    $page = $this->assertGraphNode($document, $tags['neo_seo_canonical']);
    $this->assertSame(['WebPage', 'CollectionPage'], $page['@type']);
    $this->assertStringEndsWith('/people-list', $page['@id']);
    $this->assertGraphReferencesResolve($document);
  }

  /**
   * A view without settings gets the global templates and a plain WebPage.
   */
  public function testNoSettings(): void {
    $this->createView([]);
    [$document, $tags, $title] = $this->page();
    $this->assertSame('Global title | Acme', $title);
    $this->assertSame('WebPage', $this->assertGraphNode($document, $tags['neo_seo_canonical'])['@type']);
  }

  /**
   * The settings survive a save of the view, and validate against the schema.
   */
  public function testSettingsAreConfig(): void {
    $this->createView(['title' => 'Kept', 'description' => '', 'image' => '', 'page_type' => 'SearchResultsPage']);
    $view = View::load('people_list');
    $view->set('label', 'People list, renamed')->save();
    $display = View::load('people_list')->getDisplay('page_1');
    $this->assertSame('SearchResultsPage', $display['display_options']['display_extenders']['neo_seo']['seo']['page_type']);
  }

  /**
   * Installing the module turns the extender on; uninstalling turns it off.
   *
   * Core calls {module}_install() directly once the .install file is loaded,
   * which is what happens here; a full install would drag in neo's own
   * dependencies for nothing.
   */
  public function testInstallEnablesTheExtender(): void {
    $this->container->get('module_handler')->loadInclude('neo_seo', 'install');
    $this->config('views.settings')->set('display_extenders', ['other'])->save();
    neo_seo_install();
    $this->assertSame(['other', 'neo_seo'], $this->config('views.settings')->get('display_extenders'));
    neo_seo_install();
    $this->assertSame(['other', 'neo_seo'], $this->config('views.settings')->get('display_extenders'), 'Never listed twice.');
    neo_seo_uninstall();
    $this->assertSame(['other'], $this->config('views.settings')->get('display_extenders'));
  }

}
