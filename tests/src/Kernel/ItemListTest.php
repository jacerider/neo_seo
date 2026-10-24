<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_seo\Kernel;

use Drupal\neo_seo\Alchemist\ComponentViews;
use Drupal\neo_seo\Graph\PageContext;
use Drupal\neo_seo\Hook\NeoSeoHooks;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\views\Entity\View;
use Drupal\views\Views;
use PHPUnit\Framework\Attributes\Group;

/**
 * A collection page's ItemList: the rows on the page being viewed.
 */
#[Group('neo_seo')]
class ItemListTest extends NeoSeoKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['views', 'node', 'text', 'filter'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('node');
    $this->installConfig(['views', 'node', 'filter']);
    $this->installSchema('node', ['node_access']);
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    NodeType::create([
      'type' => 'listing',
      'name' => 'Listing',
      'third_party_settings' => ['neo_seo' => ['page_type' => 'CollectionPage']],
    ])->save();
    for ($i = 1; $i <= 25; $i++) {
      Node::create(['type' => 'article', 'title' => "Article $i", 'status' => 1])->save();
    }
  }

  /**
   * Creates a node listing: a page ten to a page, a block of three.
   */
  protected function createView(string $pageType, string $baseTable = 'node_field_data', string $id = 'nid'): void {
    View::create([
      'id' => 'listing',
      'label' => 'Listing',
      'base_table' => $baseTable,
      'display' => [
        'default' => [
          'id' => 'default',
          'display_plugin' => 'default',
          'position' => 0,
          'display_options' => [
            'title' => 'Articles',
            'pager' => ['type' => 'full', 'options' => ['items_per_page' => 10]],
            'sorts' => [
              $id => [
                'id' => $id,
                'table' => $baseTable,
                'field' => $id,
                'plugin_id' => 'standard',
                'order' => 'ASC',
              ],
            ],
          ],
        ],
        'page_1' => [
          'id' => 'page_1',
          'display_plugin' => 'page',
          'position' => 1,
          'display_options' => [
            'path' => 'listing',
            'display_extenders' => ['neo_seo' => ['seo' => ['page_type' => $pageType]]],
          ],
        ],
        'block_1' => [
          'id' => 'block_1',
          'display_plugin' => 'block',
          'position' => 2,
          'display_options' => [
            'defaults' => ['pager' => FALSE],
            'pager' => ['type' => 'some', 'options' => ['items_per_page' => 3]],
          ],
        ],
      ],
    ])->save();
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * The graph of the listing's page.
   */
  protected function page(string $query = ''): array {
    $this->pushRoute('view.listing.page_1', [], '/listing' . $query);
    $attachments = ['#attached' => []];
    $this->container->get(NeoSeoHooks::class)->pageAttachments($attachments);
    foreach ($attachments['#attached']['html_head'] ?? [] as [$element, $key]) {
      if ($key === 'neo_seo_graph') {
        return json_decode((string) $element['#value'], TRUE);
      }
    }
    return [];
  }

  /**
   * The positions and node ids of a document's ItemList.
   *
   * @return array<int, int>
   *   Node ids by position.
   */
  protected function listed(array $document, string $page): array {
    $list = $this->assertGraphNode($document, "$page#itemlist");
    $this->assertSame('ItemList', $list['@type']);
    $listed = [];
    foreach ($list['itemListElement'] as $element) {
      $this->assertSame(['@type', 'position', 'url'], array_keys($element));
      $listed[$element['position']] = (int) basename($element['url']);
    }
    return $listed;
  }

  /**
   * Page 1 lists its rows from 1; the WebPage's main entity is the list.
   */
  public function testFirstPage(): void {
    $this->createView('CollectionPage');
    $document = $this->page();
    $page = 'http://localhost/listing';
    $this->assertSame(array_combine(range(1, 10), range(1, 10)), $this->listed($document, $page));
    $webPage = $this->assertGraphNode($document, $page);
    $this->assertSame(['WebPage', 'CollectionPage'], $webPage['@type']);
    $this->assertSame(['@id' => "$page#itemlist"], $webPage['mainEntity']);
    $this->assertGraphReferencesResolve($document);
  }

  /**
   * A later page numbers its rows from the pager's first: page 3 from 21.
   */
  public function testLaterPage(): void {
    $this->createView('CollectionPage');
    $this->assertSame(
      array_combine(range(21, 25), range(21, 25)),
      $this->listed($this->page('?page=2'), 'http://localhost/listing'),
    );
  }

  /**
   * The rows the page display executed are the ones listed.
   */
  public function testExecutedRowsAreListed(): void {
    $this->createView('CollectionPage');
    // The page renders its display before the graph is built.
    $this->pushRoute('view.listing.page_1', [], '/listing');
    Views::getView('listing')->executeDisplay('page_1');
    // Executing again would find nine rows.
    Node::load(1)->delete();
    $this->assertSame(range(1, 10), array_values($this->listed($this->page(), 'http://localhost/listing')));
  }

  /**
   * No collection, no list; a listing of things without pages lists nothing.
   */
  public function testOnlyCollectionsOfPages(): void {
    $this->createView('SearchResultsPage');
    $document = $this->page();
    $this->assertNotContains('http://localhost/listing#itemlist', $this->graphIds($document));
    $this->assertArrayNotHasKey('mainEntity', $this->assertGraphNode($document, 'http://localhost/listing'));

    View::load('listing')->delete();
    $this->installEntitySchema('file');
    $this->createView('CollectionPage', 'file_managed', 'fid');
    \Drupal::entityTypeManager()->getStorage('file')->create(['uri' => 'public://a.txt'])->save();
    $this->assertNotContains('http://localhost/listing#itemlist', $this->graphIds($this->page()), 'A file has no page.');
  }

  /**
   * On an entity's page, the view a component lists: the first that pages.
   */
  public function testComponentViews(): void {
    $this->createView('');
    $block = Views::getView('listing');
    $block->executeDisplay('block_1');
    $paged = Views::getView('listing');
    $paged->executeDisplay('page_1');

    $listing = Node::create(['type' => 'listing', 'title' => 'Our articles']);
    $listing->save();
    $context = $this->container->get('neo_seo.page_context_factory')->forEntity($listing);

    $this->stubComponentViews([$block, $paged]);
    $listed = array_column($this->container->get('neo_seo.listed_items')->items($context), 'position');
    $this->assertSame(range(1, 10), $listed, 'The paged view, not the block before it.');

    $this->stubComponentViews([$block]);
    $context = $this->container->get('neo_seo.page_context_factory')->forEntity($listing);
    $this->assertSame([1, 2, 3], array_column($this->container->get('neo_seo.listed_items')->items($context), 'position'), 'Else the first.');

    $article = Node::load(2);
    $context = $this->container->get('neo_seo.page_context_factory')->forEntity($article);
    $this->assertSame([], $this->container->get('neo_seo.listed_items')->items($context), 'Only on a collection page.');
  }

  /**
   * Makes the components on every page list the given executed views.
   *
   * @param \Drupal\views\ViewExecutable[] $views
   *   The views.
   */
  protected function stubComponentViews(array $views): void {
    $stub = new class($this->container->get('module_handler'), $views) extends ComponentViews {

      /**
       * Constructs the stub with the views it gives every page.
       */
      public function __construct($moduleHandler, protected array $listed) {
        parent::__construct($moduleHandler);
      }

      /**
       * {@inheritdoc}
       */
      public function views(PageContext $context): array {
        return $this->listed;
      }

    };
    $this->container->set('neo_seo.component_views', $stub);
    $this->container->set('neo_seo.listed_items', NULL);
    $this->container->set('Drupal\neo_seo\Graph\ListedItems', NULL);
  }

}
