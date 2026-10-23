<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_seo\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\neo_seo\PageUrl;
use Drupal\neo_seo\SeoOverrides;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\pathauto\Entity\PathautoPattern;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * A page's URL: the slug editors may change, and a URL of its own.
 */
#[Group('neo_seo')]
class PageUrlTest extends NeoSeoKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node', 'text', 'filter', 'path', 'path_alias', 'pathauto'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('node');
    $this->installEntitySchema('path_alias');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('system', ['sequences']);
    $this->installConfig(['node', 'filter', 'pathauto']);
    $this->config('neo_seo.settings')->set('override_entity_types', ['node'])->save();
    NodeType::create(['type' => 'project', 'name' => 'Project'])->save();
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    $this->pattern('project', '/projects/' . PageUrl::TOKEN);
    $this->pattern('article', '/news/[node:title]');
    $this->setUpCurrentUser([], ['access content', 'bypass node access', 'edit neo_seo overrides', 'create url aliases']);
  }

  /**
   * The slug token makes the alias: the editor's slug, else the title.
   */
  public function testSlugTokenMakesTheAlias(): void {
    $node = $this->node('project', 'Plant Expansion');
    $this->assertSame('/projects/plant-expansion', $this->alias($node), 'No slug: the title, as [node:title] would give.');

    $node->set(SeoOverrides::FIELD, ['slug' => 'Phase 2!'])->save();
    $this->assertSame('/projects/phase-2', $this->alias($node), 'The slug, cleaned by pathauto.');

    $node->set(SeoOverrides::FIELD, ['slug' => ''])->save();
    $this->assertSame('/projects/plant-expansion', $this->alias($node), 'Clearing the slug returns to the title.');
  }

  /**
   * Managed bundles: a pattern with the token, or custom URLs allowed.
   */
  public function testManagedBundles(): void {
    $url = $this->pageUrl();
    $this->assertTrue($url->manages($this->node('project', 'A')));
    $article = $this->node('article', 'B');
    $this->assertFalse($url->manages($article), 'A title pattern and no custom URLs: core keeps the URL.');

    $this->allowCustom('article');
    $this->assertTrue($url->manages($article));
    $this->assertFalse($url->usesSlug($article), 'Still no slug without the token.');
  }

  /**
   * Custom URLs: the bundle plus `create url aliases`, or admin rights.
   */
  public function testWhoMayCustomize(): void {
    $url = $this->pageUrl();
    $node = $this->node('project', 'A');
    $creator = $this->createUser(['create url aliases']);
    $admin = $this->createUser(['administer url aliases']);

    $this->assertFalse($url->mayCustomize($node, $creator), 'Slug only, by default.');
    $this->assertTrue($url->mayCustomize($node, $admin));
    $this->allowCustom('project');
    $this->assertTrue($url->mayCustomize($this->reload($node), $creator));
  }

  /**
   * What an editor may not do is dropped; anyone may hand a URL back.
   */
  public function testApplyKeepsToWhatTheEditorMay(): void {
    $url = $this->pageUrl();
    $node = $this->node('project', 'Plant Expansion');
    $slugOnly = $this->createUser(['create url aliases']);
    $admin = $this->createUser(['administer url aliases']);

    $url->apply($node, $slugOnly, ['url_custom' => TRUE, 'url_alias' => '/elsewhere']);
    $node->save();
    $this->assertSame('/projects/plant-expansion', $this->alias($node), 'A custom URL from a slug-only editor is dropped.');

    $node = $this->reload($node);
    $url->apply($node, $admin, ['url_custom' => TRUE, 'url_alias' => ' our-plant/ ']);
    $node->save();
    $node = $this->reload($node);
    $this->assertSame('/our-plant', $this->alias($node), 'An allowed custom URL is normalized and saved.');
    $this->assertTrue($url->isCustom($node));

    $node->setTitle('Renamed')->save();
    $this->assertSame('/our-plant', $this->alias($this->reload($node)), 'Pathauto leaves a custom URL alone.');

    $node = $this->reload($node);
    $url->apply($node, $slugOnly, ['url_custom' => FALSE]);
    $node->save();
    $node = $this->reload($node);
    $this->assertSame('/projects/renamed', $this->alias($node), 'A slug-only editor may hand the URL back to the pattern.');
    $this->assertFalse($url->isCustom($node));
  }

  /**
   * Another page's URL is refused, and the editor told why.
   */
  public function testTakenCustomUrlIsRefused(): void {
    $url = $this->pageUrl();
    $this->node('project', 'Taken');
    $node = $this->node('project', 'Mine');
    $admin = $this->createUser(['administer url aliases']);

    $url->apply($node, $admin, ['url_custom' => TRUE, 'url_alias' => '/projects/taken']);
    $node->save();
    $this->assertSame('/projects/mine', $this->alias($this->reload($node)));

    $draft = $this->reload($node);
    $draft->get('path')->first()->set('alias', '/projects/taken');
    $draft->get('path')->first()->set('pathauto', 0);
    $this->assertStringContainsString('/projects/taken', (string) $url->describe($draft)['error']);
  }

  /**
   * What describe() shows is what saving gives, split where the slug starts.
   */
  public function testDescribeMatchesTheSavedAlias(): void {
    $url = $this->pageUrl();
    $this->node('project', 'Same');
    $node = $this->node('project', 'Other');

    $node->set(SeoOverrides::FIELD, ['slug' => 'Same']);
    $described = $url->describe($node);
    $this->assertSame('/projects/same-0', $described['alias'], 'Made unique as pathauto would.');
    $this->assertSame('/projects/', $described['prefix']);
    $this->assertSame('same', $described['slug'], 'The slug as the editor keeps it: never with the -0.');
    $this->assertTrue($described['taken']);
    $this->assertContains('Another page has this address, so this one will be /projects/same-0.', array_column($described['notes'], 'text'));
    $this->assertTrue($described['changed']);
    $this->assertSame('/projects/other', $described['saved']);
    $this->assertStringEndsWith('/projects/same-0', (string) $described['url']);

    $node->save();
    $this->assertSame($described['alias'], $this->alias($this->reload($node)));
  }

  /**
   * The draft render gives the URL the page will have, slug and all.
   */
  public function testDraftRenderShowsTheNewUrl(): void {
    $node = $this->node('project', 'Plant Expansion');
    $renderer = $this->container->get('neo_seo.draft_renderer');
    $draft = $renderer->freshCopy($node);
    $draft->set(SeoOverrides::FIELD, ['slug' => 'phase two']);
    $render = $renderer->render($draft);

    $this->assertSame('/projects/phase-two', $render['path']['alias']);
    $this->assertStringEndsWith('/projects/phase-two', $render['url']);
    $this->assertSame($render['url'], $render['head']['canonical']);
    $this->assertSame('/projects/plant-expansion', $this->alias($this->reload($node)), 'Nothing is saved.');

    $article = $renderer->render($renderer->freshCopy($this->node('article', 'Plain')));
    $this->assertNull($article['path'], 'An unmanaged page has no path block.');
  }

  /**
   * The edit form shows neo_seo's URL controls in place of core's widget.
   */
  public function testEditFormReplacesThePathWidget(): void {
    $project = $this->form($this->node('project', 'A'));
    $this->assertFalse($project['path']['#access'], 'Core’s path widget is hidden.');
    // The controls sit in a URL section of their own.
    $element = $project[SeoOverrides::FIELD]['widget'][0]['url']['url_field'];
    $this->assertNotFalse($element['slug']['#access'] ?? TRUE);
    $this->assertFalse($element['url_custom']['#access'], 'No custom URL for a slug-only editor.');
    $this->assertArrayHasKey('url_preview', $element);

    $article = $this->form($this->node('article', 'B'));
    $this->assertNotFalse($article['path']['#access'] ?? TRUE, 'Core’s widget stays where neo_seo has nothing to offer.');
    $this->assertArrayNotHasKey('url', $article[SeoOverrides::FIELD]['widget'][0], 'No URL section either.');
  }

  /**
   * A pathauto pattern for a node bundle.
   */
  protected function pattern(string $bundle, string $pattern): void {
    $entity = PathautoPattern::create([
      'id' => $bundle,
      'type' => 'canonical_entities:node',
      'pattern' => $pattern,
      'weight' => 0,
    ]);
    $entity->addSelectionCondition([
      'id' => 'entity_bundle:node',
      'bundles' => [$bundle => $bundle],
      'negate' => FALSE,
      'context_mapping' => ['node' => 'node'],
    ]);
    $entity->save();
  }

  /**
   * Lets editors give a bundle's pages a URL of their own.
   */
  protected function allowCustom(string $bundle): void {
    NodeType::load($bundle)->setThirdPartySetting('neo_seo', 'custom_url', TRUE)->save();
  }

  /**
   * A saved node.
   */
  protected function node(string $bundle, string $title): NodeInterface {
    $node = Node::create(['type' => $bundle, 'title' => $title]);
    $node->save();
    return $node;
  }

  /**
   * A node as stored.
   */
  protected function reload(NodeInterface $node): NodeInterface {
    $this->container->get('entity_type.manager')->getStorage('node')->resetCache();
    $this->container->get('pathauto.generator')->resetCaches();
    return Node::load($node->id());
  }

  /**
   * A node's stored alias.
   */
  protected function alias(NodeInterface $node): ?string {
    return $this->container->get('path_alias.repository')->lookupBySystemPath('/node/' . $node->id(), 'en')['alias'] ?? NULL;
  }

  /**
   * The page URL service.
   */
  protected function pageUrl(): PageUrl {
    return $this->container->get('neo_seo.page_url');
  }

  /**
   * A node's built edit form.
   */
  protected function form(NodeInterface $node): array {
    $formObject = $this->container->get('entity_type.manager')->getFormObject('node', 'default')->setEntity($node);
    $formState = new FormState();
    return $this->container->get('form_builder')->buildForm($formObject, $formState);
  }

}
