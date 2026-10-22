<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_seo\Kernel;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\menu_link_content\MenuLinkContentInterface;
use Drupal\neo_seo\PageUrl;
use Drupal\neo_seo\PageUrlPatterns;
use Drupal\neo_seo\SeoOverrides;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\pathauto\Entity\PathautoPattern;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * Parents in page URLs, the cascade, and moving patterns onto the tokens.
 */
#[Group('neo_seo')]
class PageUrlParentsTest extends NeoSeoKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'text',
    'filter',
    'path',
    'path_alias',
    'pathauto',
    'taxonomy',
    'link',
    'menu_ui',
    'menu_link_content',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    foreach (['node', 'path_alias', 'taxonomy_term', 'menu_link_content'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installSchema('node', ['node_access']);
    $this->installSchema('system', ['sequences']);
    $this->installConfig(['node', 'filter', 'pathauto', 'system']);
    $this->config('neo_seo.settings')->set('override_entity_types', ['node', 'taxonomy_term'])->save();
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    Vocabulary::create(['vid' => 'market', 'name' => 'Market'])->save();
    $this->setUpCurrentUser([], ['access content', 'bypass node access', 'edit neo_seo overrides']);
  }

  /**
   * Parent terms give their slugs, else their names; children follow.
   */
  public function testTermParents(): void {
    $this->pattern('market', 'taxonomy_term', '/markets/' . PageUrl::PARENTS_TOKEN . '/' . PageUrl::TOKEN);
    $water = $this->term('Water Resources');
    $pumps = $this->term('Pumps', $water);
    $this->assertSame('/markets/water-resources/pumps', $this->alias($pumps), 'No slugs: core’s parents path.');

    $water = Term::load($water->id());
    $water->set(SeoOverrides::FIELD, ['slug' => 'water'])->save();
    $this->assertSame('/markets/water', $this->alias($water));
    $this->assertSame('/markets/water/pumps', $this->alias($pumps), 'The child follows its parent’s slug.');
  }

  /**
   * Menu parents give their slugs, else their link titles; children follow.
   */
  public function testMenuParentsAndTheCascade(): void {
    $this->pattern('page', 'node', '/' . PageUrl::PARENTS_TOKEN . '/' . PageUrl::TOKEN);
    $about = $this->page('About Acme');
    $aboutLink = $this->link($about, 'About us');
    $team = $this->page('Our Team');
    $teamLink = $this->link($team, 'Team', $aboutLink);
    $lead = $this->page('Leadership');
    $this->link($lead, 'Leadership', $teamLink);
    $this->resave($team, $lead);
    $this->assertSame('/about-us/team/leadership', $this->alias($lead), 'No slugs: the menu link titles, as today.');

    $about = Node::load($about->id());
    $about->set(SeoOverrides::FIELD, ['slug' => 'about']);
    $this->assertSame(2, $this->pageUrl()->describe($about)['children'], 'The editor is told both pages under it move.');
    $about->save();
    $this->assertSame('/about/our-team', $this->alias($team), 'A page’s new slug moves its menu children.');
    // A parent without a slug gives its menu link's title, as core's parents
    // path does, even where the page's own segment is its title.
    $this->assertSame('/about/team/leadership', $this->alias($lead), 'And theirs.');

    $teamLink = MenuLinkContent::load($teamLink->id());
    $teamLink->set('parent', '')->save();
    $this->assertSame('/our-team', $this->alias($team), 'Moving a menu link re-aliases its page.');
    $this->assertSame('/team/leadership', $this->alias($lead), 'And the pages under it.');
  }

  /**
   * A child with a custom URL keeps it when its parent moves.
   */
  public function testCustomUrlChildIsLeftAlone(): void {
    $this->pattern('page', 'node', '/' . PageUrl::PARENTS_TOKEN . '/' . PageUrl::TOKEN);
    $about = $this->page('About');
    $aboutLink = $this->link($about, 'About');
    $team = $this->page('Team');
    $this->link($team, 'Team', $aboutLink);
    $team = Node::load($team->id());
    $team->get('path')->first()->set('alias', '/people');
    $team->get('path')->first()->set('pathauto', 0);
    $team->save();

    $about = Node::load($about->id());
    $this->assertSame([], $this->pageUrl()->descendants($about));
    $about->set(SeoOverrides::FIELD, ['slug' => 'company'])->save();
    $this->assertSame('/people', $this->alias($team));
  }

  /**
   * The command's plan swaps only what is exact, and checks every page.
   */
  public function testPatternPlan(): void {
    NodeType::create(['type' => 'news', 'name' => 'News'])->save();
    NodeType::create(['type' => 'hidden', 'name' => 'Hidden'])->save();
    $this->pattern('page', 'node', '/[node:menu-link:parents:join-path]/[node:title]');
    $this->pattern('news', 'node', '/news/[node:title]-[node:nid]');
    $this->pattern('market', 'taxonomy_term', '/markets/[term:parents:join-path]/[term:name]');
    $this->pattern('hidden', 'node', '/hidden/[node:title]');
    \Drupal::service('entity_display.repository')->getFormDisplay('node', 'hidden')->removeComponent(SeoOverrides::FIELD)->save();
    $about = $this->page('About Acme');
    $aboutLink = $this->link($about, 'About us');
    $team = $this->page('Our Team');
    $this->link($team, 'Team', $aboutLink);
    $this->resave($team);
    $this->term('Pumps', $this->term('Water'));

    $plan = $this->patterns()->plan();
    $this->assertSame('swap', $plan['page']['status']);
    $this->assertSame('/' . PageUrl::PARENTS_TOKEN . '/' . PageUrl::TOKEN, $plan['page']['to']);
    $this->assertSame(2, $plan['page']['checked']);
    $this->assertSame([], $plan['page']['moving'], 'No slugs set: no page moves.');
    $this->assertSame('swap', $plan['market']['status']);
    $this->assertSame([], $plan['market']['moving']);
    $this->assertSame('skip', $plan['news']['status'], 'A title that is not the whole last segment is left alone.');
    $this->assertSame('skip', $plan['hidden']['status']);
    $this->assertStringContainsString('hidden', $plan['hidden']['reason']);

    $this->assertSame(2, $this->patterns()->apply($plan));
    $this->assertSame('/' . PageUrl::PARENTS_TOKEN . '/' . PageUrl::TOKEN, PathautoPattern::load('page')->getPattern());
    $this->assertSame('/news/[node:title]-[node:nid]', PathautoPattern::load('news')->getPattern());
    $this->assertSame('done', $this->patterns()->plan('page')['page']['status'], 'Run again, it has nothing left to do.');
  }

  /**
   * Only an exact label token or core's parents path is swapped.
   */
  public function testSwap(): void {
    $this->assertSame('/a/[neo:slug]', PageUrlPatterns::swap('/a/[node:title]', '[node:title]'));
    $this->assertSame('[neo:slug]', PageUrlPatterns::swap('[node:title]', '[node:title]'));
    $this->assertSame('/[neo:parents:join-path]/[neo:slug]/', PageUrlPatterns::swap('/[node:menu-link:parents:join-path]/[node:title]/', '[node:title]'));
    $this->assertSame('/[node:title]/x', PageUrlPatterns::swap('/[node:title]/x', '[node:title]'));
    $this->assertSame('/a/[node:title]-[node:nid]', PageUrlPatterns::swap('/a/[node:title]-[node:nid]', '[node:title]'));
  }

  /**
   * A pathauto pattern for a bundle.
   */
  protected function pattern(string $bundle, string $entityTypeId, string $pattern): void {
    $entity = PathautoPattern::create([
      'id' => $bundle,
      'type' => 'canonical_entities:' . $entityTypeId,
      'pattern' => $pattern,
      'weight' => 0,
    ]);
    $tokenType = $entityTypeId === 'taxonomy_term' ? 'taxonomy_term' : 'node';
    $entity->addSelectionCondition([
      'id' => 'entity_bundle:' . $entityTypeId,
      'bundles' => [$bundle => $bundle],
      'negate' => FALSE,
      'context_mapping' => [$tokenType => $tokenType],
    ]);
    $entity->save();
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
   * A saved market term.
   */
  protected function term(string $name, ?Term $parent = NULL): Term {
    $term = Term::create(['vid' => 'market', 'name' => $name, 'parent' => $parent ? [$parent->id()] : []]);
    $term->save();
    return $term;
  }

  /**
   * A main menu link to a page.
   */
  protected function link(NodeInterface $node, string $title, ?MenuLinkContentInterface $parent = NULL): MenuLinkContentInterface {
    $link = MenuLinkContent::create([
      'title' => $title,
      'link' => ['uri' => 'entity:node/' . $node->id()],
      'menu_name' => 'main',
      'parent' => $parent?->getPluginId() ?? '',
    ]);
    $link->save();
    return $link;
  }

  /**
   * Saves pages again, as an editor would after placing them in the menu.
   */
  protected function resave(NodeInterface ...$nodes): void {
    foreach ($nodes as $node) {
      drupal_static_reset('token_menu_link_load_all_parents');
      Node::load($node->id())->save();
    }
  }

  /**
   * A page's stored alias.
   */
  protected function alias(ContentEntityInterface $entity): ?string {
    return $this->container->get('path_alias.repository')->lookupBySystemPath('/' . $entity->toUrl()->getInternalPath(), 'en')['alias'] ?? NULL;
  }

  /**
   * The page URL service.
   */
  protected function pageUrl(): PageUrl {
    return $this->container->get('neo_seo.page_url');
  }

  /**
   * The pattern planning service.
   */
  protected function patterns(): PageUrlPatterns {
    return $this->container->get('neo_seo.page_url_patterns');
  }

}
