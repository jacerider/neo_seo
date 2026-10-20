<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_seo\Kernel;

use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\entity_test\EntityTestHelper;
use Drupal\neo_seo\Entity\SeoDefaults;
use Drupal\neo_seo\Hook\NeoSeoHooks;
use PHPUnit\Framework\Attributes\Group;

/**
 * Head tags, fed by layers of default templates.
 */
#[Group('neo_seo')]
class HeadTagsTest extends NeoSeoKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['entity_test', 'text', 'filter'];

  /**
   * The page's entity.
   */
  protected EntityTest $entity;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('entity_test');
    $this->config('system.site')->set('page.front', '/user/login')->set('slogan', 'Engineering the grid')->save();
    EntityTestHelper::createBundle('report', 'Report');
    $this->entity = EntityTest::create([
      'type' => 'report',
      'name' => 'Grid & growth',
      'created' => 1700000000,
    ]);
    $this->entity->save();

    // Templates that use only core and token-module tokens: the [neo:*]
    // tokens belong to a module these tests do not need.
    $this->setTemplates('global', [
      'title' => '[entity_test:name] | [site:name]',
      'description' => '[site:slogan]',
      'social_title' => '[entity_test:name]',
      'og_type' => 'website',
      'image' => '/sites/default/files/share.png',
      'robots' => '',
    ]);
  }

  /**
   * Writes a layer of templates.
   */
  protected function setTemplates(string $id, array $values): void {
    $defaults = SeoDefaults::load($id) ?? SeoDefaults::create(['id' => $id, 'label' => $id]);
    $defaults->setValues($values)->save();
  }

  /**
   * Renders the head of a route and returns its tags, keyed by attachment key.
   *
   * @return array<string, array>
   *   The html_head render elements.
   */
  protected function head(string $route, array $parameters, string $path): array {
    $this->pushRoute($route, $parameters, $path);
    $attachments = ['#attached' => []];
    $this->container->get(NeoSeoHooks::class)->pageAttachments($attachments);
    $tags = [];
    foreach ($attachments['#attached']['html_head'] ?? [] as [$element, $key]) {
      $tags[$key] = $element;
    }
    return $tags;
  }

  /**
   * The content of a meta tag, or NULL when it is not printed.
   */
  protected function content(array $tags, string $key): ?string {
    return $tags[$key]['#attributes']['content'] ?? NULL;
  }

  /**
   * The <title> the theme would print.
   */
  protected function title(): ?string {
    $variables = ['head_title' => ['title' => 'Core title', 'name' => 'Acme']];
    $this->container->get(NeoSeoHooks::class)->preprocessHtml($variables);
    return implode(' | ', $variables['head_title']);
  }

  /**
   * The global layer alone, on an entity page.
   */
  public function testGlobalDefaults(): void {
    $tags = $this->head('entity.entity_test.canonical', ['entity_test' => $this->entity], '/entity_test/' . $this->entity->id());

    $this->assertSame('Grid & growth | Acme', $this->title(), 'Tokens resolve to plain text, entities decoded.');
    $this->assertSame('Engineering the grid', $this->content($tags, 'neo_seo_description'));
    $canonical = $tags['neo_seo_canonical']['#attributes']['href'];
    $this->assertStringEndsWith('/entity_test/' . $this->entity->id(), $canonical);
    $this->assertSame($canonical, $this->content($tags, 'neo_seo_og_url'), 'og:url is the canonical URL.');
    $this->assertSame('Grid & growth', $this->content($tags, 'neo_seo_og_title'));
    $this->assertSame('website', $this->content($tags, 'neo_seo_og_type'));
    $this->assertSame('en_US', $this->content($tags, 'neo_seo_og_locale'));
    $this->assertSame('Acme', $this->content($tags, 'neo_seo_og_site_name'));
    $this->assertSame('http://localhost/sites/default/files/share.png', $this->content($tags, 'neo_seo_og_image'), 'A relative image becomes absolute.');
    $this->assertSame('summary_large_image', $this->content($tags, 'neo_seo_twitter_card'));
    $this->assertArrayNotHasKey('neo_seo_robots', $tags, 'Empty robots prints nothing.');
    $this->assertArrayNotHasKey('neo_seo_article_published_time', $tags);
    $this->assertArrayHasKey('neo_seo_graph', $tags);

    // The graph and the head agree on the page.
    $document = json_decode((string) $tags['neo_seo_graph']['#value'], TRUE);
    $this->assertGraphNode($document, $canonical);
  }

  /**
   * Entity type and bundle layers win field by field, only where set.
   */
  public function testLayersWinFieldByField(): void {
    $this->setTemplates('entity_test', ['description' => 'Every test entity', 'og_type' => 'article']);
    $this->setTemplates('entity_test__report', ['title' => 'Report: [entity_test:name]', 'description' => '']);

    $tags = $this->head('entity.entity_test.canonical', ['entity_test' => $this->entity], '/entity_test/' . $this->entity->id());

    $this->assertSame('Report: Grid & growth', $this->title(), 'The bundle sets the title.');
    $this->assertSame('Every test entity', $this->content($tags, 'neo_seo_description'), 'An empty bundle value keeps the entity type’s.');
    $this->assertSame('article', $this->content($tags, 'neo_seo_og_type'));
    $this->assertSame(date('c', 1700000000), $this->content($tags, 'neo_seo_article_published_time'));

    // Another bundle does not get the report layer.
    EntityTestHelper::createBundle('memo', 'Memo');
    $memo = EntityTest::create(['type' => 'memo', 'name' => 'Memo']);
    $memo->save();
    $this->head('entity.entity_test.canonical', ['entity_test' => $memo], '/entity_test/' . $memo->id());
    $this->assertSame('Memo | Acme', $this->title());
  }

  /**
   * The front layer sits on top of the chain instead of replacing it.
   */
  public function testFrontLayersOnTopOfTheChain(): void {
    $this->setTemplates('front', ['title' => '[site:name]']);
    $tags = $this->head('user.login', [], '/user/login');
    $this->assertSame('Acme', $this->title());
    $this->assertSame('Engineering the grid', $this->content($tags, 'neo_seo_description'), 'The global description still applies on the front page.');

    // Without the front layer, the entity token resolves to nothing and its
    // separator goes with it.
    SeoDefaults::load('front')->delete();
    $this->head('user.login', [], '/user/login');
    $this->assertSame('Acme', $this->title());
  }

  /**
   * The 404 layer adds robots.
   */
  public function testNotFoundIsNoindex(): void {
    $this->setTemplates('404', ['robots' => 'noindex, follow']);
    $tags = $this->head('system.404', [], '/system/404');
    $this->assertSame('noindex, follow', $this->content($tags, 'neo_seo_robots'));
  }

  /**
   * Verification codes print on the front page only.
   */
  public function testVerificationOnFrontOnly(): void {
    $this->config('neo_seo.settings')->set('verification.google', 'abc123')->save();
    $this->resetSettings();
    $tags = $this->head('user.login', [], '/user/login');
    $this->assertSame('abc123', $this->content($tags, 'neo_seo_google_site_verification'));
    $this->assertArrayNotHasKey('neo_seo_msvalidate_01', $tags, 'An empty code prints nothing.');

    $tags = $this->head('entity.entity_test.canonical', ['entity_test' => $this->entity], '/entity_test/' . $this->entity->id());
    $this->assertArrayNotHasKey('neo_seo_google_site_verification', $tags);
  }

  /**
   * Another module adds a tag with a template field of its own.
   */
  public function testTagFromAnotherModule(): void {
    $this->assertArrayHasKey('test_field', $this->container->get('plugin.manager.neo_seo_head_tag')->getFields());
    $this->setTemplates('global', ['test_field' => 'From [site:name]'] + SeoDefaults::load('global')->getValues());
    $tags = $this->head('user.login', [], '/user/login');
    $this->assertSame('From Acme', $this->content($tags, 'neo_seo_neo_seo_test'));
  }

  /**
   * While metatag is enabled the head stays metatag's, unless asked otherwise.
   */
  public function testStandsAsideForMetatag(): void {
    $this->enableModules(['metatag']);
    $this->resetSettings();
    $tags = $this->head('user.login', [], '/user/login');
    $this->assertArrayNotHasKey('neo_seo_description', $tags);
    $this->assertArrayHasKey('neo_seo_graph', $tags, 'The graph is not a head tag metatag prints.');
    $this->assertSame('Core title | Acme', $this->title(), 'The title is left alone.');

    $this->config('neo_seo.settings')->set('head_alongside_metatag', TRUE)->save();
    $this->resetSettings();
    $tags = $this->head('user.login', [], '/user/login');
    $this->assertArrayHasKey('neo_seo_description', $tags);
  }

  /**
   * Core's canonical and shortlink leave the page's own entity only.
   */
  public function testCoreLinksAreRemovedFromThePageEntity(): void {
    $this->pushRoute('entity.entity_test.canonical', ['entity_test' => $this->entity], '/entity_test/' . $this->entity->id());
    $display = EntityViewDisplay::create(['targetEntityType' => 'entity_test', 'bundle' => 'report', 'mode' => 'full']);
    $links = [
      [['rel' => 'canonical', 'href' => '/x']],
      [['rel' => 'shortlink', 'href' => '/y']],
      [['rel' => 'alternate', 'href' => '/z']],
    ];

    $build = ['#attached' => ['html_head_link' => $links]];
    $this->container->get(NeoSeoHooks::class)->entityViewAlter($build, $this->entity, $display);
    $this->assertSame([[['rel' => 'alternate', 'href' => '/z']]], array_values($build['#attached']['html_head_link']));

    $other = EntityTest::create(['type' => 'report', 'name' => 'Other']);
    $other->save();
    $build = ['#attached' => ['html_head_link' => $links]];
    $this->container->get(NeoSeoHooks::class)->entityViewAlter($build, $other, $display);
    $this->assertCount(3, $build['#attached']['html_head_link'], 'An entity embedded in the page keeps its links.');
  }

  /**
   * Reinstalling optional config never overwrites an edited template.
   */
  public function testShippedDefaultsNeverOverwrite(): void {
    $this->setTemplates('global', ['title' => 'Edited']);
    $this->container->get('config.installer')->installOptionalConfig();
    $this->assertSame(['title' => 'Edited'], SeoDefaults::load('global')->getValues());
  }

  /**
   * The shipped defaults install and match what Neo sites use today.
   */
  public function testShippedDefaults(): void {
    SeoDefaults::load('global')->delete();
    $this->container->get('config.installer')->installOptionalConfig();
    $global = SeoDefaults::load('global')->getValues();
    $this->assertSame('[neo:title] [neo-seo:separator] [site:name]', $global['title']);
    $this->assertSame('[neo:description]', $global['description']);
    $this->assertSame('[neo:image]', $global['image']);
    $this->assertSame('noindex, follow', SeoDefaults::load('404')->getValues()['robots']);
    $this->assertFalse(SeoDefaults::load('global')->isDeletable());
  }

}
