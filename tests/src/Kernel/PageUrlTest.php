<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_seo\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\neo_seo\Hook\BundleMappingHooks;
use Drupal\neo_seo\PageUrl;
use Drupal\neo_seo\PageUrlField;
use Drupal\neo_seo\SeoOverrides;
use Drupal\neo_seo_panel\Form\SeoPanelForm;
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
  protected static $modules = ['node', 'text', 'filter', 'path', 'path_alias', 'pathauto', 'neo_seo_panel'];

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
    $this->assertSame('/projects/same', $described['wanted']);
    $move = $this->move($node);
    $this->assertSame('warning', $move['tone'], 'pathauto changes what the editor asked for.');
    $this->assertSame('/projects/same-0', $move['to'], 'The diff shows the real address.');
    $this->assertContains('Another page is /projects/same, so this one gets -0.', $this->notes($node));

    $this->assertTrue($described['changed']);
    $this->assertSame('/projects/other', $described['saved']);
    $this->assertStringEndsWith('/projects/same-0', (string) $described['url']);

    $node->save();
    $this->assertSame($described['alias'], $this->alias($this->reload($node)));

    // A page already made unique does not move: the taken slug is a warning
    // of its own.
    $twin = $this->reload($this->node('project', 'Same'));
    $this->assertNull($this->move($twin));
    $callouts = $url->describe($twin)['callouts'];
    $this->assertSame([['taken', 'warning']], array_map(fn($callout) => [$callout['kind'], $callout['tone']], $callouts));
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
    $element = $this->urlField($project);
    $this->assertNotFalse($element['slug']['#access'] ?? TRUE);
    $this->assertFalse($element['url_custom']['#access'], 'No custom URL for a slug-only editor.');
    $this->assertArrayHasKey('url_preview', $element);

    $article = $this->form($this->node('article', 'B'));
    $this->assertNotFalse($article['path']['#access'] ?? TRUE, 'Core’s widget stays where neo_seo has nothing to offer.');
    $this->assertArrayNotHasKey('neo_seo_url', $article, 'No URL section either.');
  }

  /**
   * The inputs are named for what they hold; an empty slug follows the title.
   */
  public function testLabelsAndPlaceholder(): void {
    $node = $this->node('project', 'Plant Expansion');
    $admin = ['access content', 'bypass node access', 'edit neo_seo overrides', 'administer url aliases'];
    $this->setCurrentUser($this->createUser($admin));
    $field = $this->urlField($this->form($node));
    $this->assertSame('URL slug', (string) $field['slug']['#title']);
    $this->assertStringContainsString('Leave empty to follow the title.', (string) $field['slug']['#description']);
    $this->assertSame('Custom URL', (string) $field['url_alias']['#title']);
    $this->assertStringContainsString('The whole address after the domain', (string) $field['url_alias']['#description']);
    $this->assertSame('plant-expansion', $field['slug']['#placeholder'], 'The slug the title gives.');

    // A new title, not yet saved: the placeholder the draft render gives.
    $node->setTitle('Plant Expansion, Phase Two');
    $this->assertSame('plant-expansion-phase-two', $this->pageUrl()->describe($node)['placeholder']);
  }

  /**
   * The pattern's part of the address is stacked above the slug, read with it.
   */
  public function testStackedPrefix(): void {
    $slug = $this->urlField($this->form($this->node('project', 'Plant Expansion')))['slug'];
    $this->assertStringContainsString('>/projects/</span>', (string) $slug['#field_prefix']);
    $this->assertContains('neo-seo-url-slug', $slug['#wrapper_attributes']['class']);
    $describedBy = explode(' ', $slug['#attributes']['aria-describedby']);
    $this->assertSame($slug['#id'] . '--prefix', $describedBy[0], 'The prefix is read first.');
    $this->assertStringContainsString('id="' . $describedBy[0] . '"', (string) $slug['#field_prefix']);
    $this->assertContains($slug['#id'] . '--description', $describedBy, 'Then the help.');
  }

  /**
   * Where the pattern has no slug, the site sets the address: shown read-only.
   */
  public function testAutomaticAddressIsReadOnly(): void {
    $this->allowCustom('article');
    $node = $this->node('article', 'Plain News');
    $field = $this->urlField($this->form($node));
    $this->assertFalse($field['slug']['#access'], 'No slug to change.');
    $this->assertNotFalse($field['url_auto']['#access']);
    $this->assertStringContainsString('/news/plain-news', (string) $field['url_auto']['#markup']);
    $this->assertNotFalse($field['url_custom']['#access'], 'Custom stays for an editor who may set one.');
    $this->assertContains('The site sets this page’s address.', $this->notes($node));
  }

  /**
   * A custom URL is cleaned segment by segment, also when posted raw.
   */
  public function testCustomUrlIsCleaned(): void {
    $url = $this->pageUrl();
    $this->assertSame('/about-us/team', $url->normalize('/About Us/Team!'));
    $this->assertSame('/our-plant/phase-2', $url->normalize('  our plant//Phase 2/ '));
    $this->assertSame('', $url->normalize(' / '));

    $node = $this->node('project', 'Plant Expansion');
    $admin = $this->createUser(['administer url aliases']);
    $url->apply($node, $admin, ['url_custom' => TRUE, 'url_alias' => 'Our Plant/Phase One!']);
    $this->assertSame('Our Plant/Phase One!', $url->describe($node)['input'], 'What was typed, for the panel to compare.');
    $this->assertSame('/our-plant/phase-one', $url->describe($node)['alias']);
    $node->save();
    $this->assertSame('/our-plant/phase-one', $this->alias($this->reload($node)), 'A posted raw URL is cleaned on save.');
  }

  /**
   * A page's existing custom URL is kept as written: no one asked to move it.
   */
  public function testExistingCustomUrlIsKept(): void {
    $url = $this->pageUrl();
    $node = $this->node('project', 'Plant Expansion');
    $node->get('path')->first()->set('alias', '/Our_Plant');
    $node->get('path')->first()->set('pathauto', 0);
    $node->save();
    $node = $this->reload($node);
    $this->assertSame('/Our_Plant', $url->describe($node)['alias']);
    $this->assertFalse($url->describe($node)['changed']);

    $admin = $this->createUser(['administer url aliases']);
    $url->apply($node, $admin, ['url_custom' => TRUE, 'url_alias' => '/Our_Plant']);
    $node->save();
    $this->assertSame('/Our_Plant', $this->alias($this->reload($node)));
  }

  /**
   * A move is one callout: from where to where, why, and what links do.
   */
  public function testMoveCallout(): void {
    $node = $this->node('project', 'Plant Expansion');
    $node->set(SeoOverrides::FIELD, ['slug' => 'phase two']);
    $move = $this->move($node);
    $this->assertSame(['/projects/plant-expansion', '/projects/phase-two'], [$move['from'], $move['to']]);
    $this->assertNull($move['cause']);
    $this->assertSame('warning', $move['tone'], 'Links will break: no redirect module.');
    $this->assertContains('Links to the old address will stop working.', $this->notes($node));
    $this->assertSame(['url_custom' => FALSE, 'slug' => '', 'url_alias' => '/projects/plant-expansion'], $move['keep'], 'Keeping the address puts the controls back.');

    // A new title moves a page with no slug, and the slug can pin it.
    $retitled = $this->reload($node)->setTitle('Plant Upgrade');
    $move = $this->move($retitled);
    $this->assertSame('/projects/plant-upgrade', $move['to']);
    $this->assertSame('Because the title changed.', (string) $move['cause']);
    $this->assertSame('plant-expansion', $move['keep']['slug'], 'Keeping it pins the slug the page has now.');

    $this->enableModules(['link', 'views', 'redirect']);
    $this->installConfig(['redirect']);
    $this->config('redirect.settings')->set('auto_redirect', TRUE)->save();
    $this->assertSame('info', $this->move($node)['tone']);
    $this->assertContains('The old address will redirect here.', $this->notes($node));
    $this->assertNotContains('Links to the old address will stop working.', $this->notes($node));

    // An unpublished page has no visitors to lose: the diff, no link line.
    $node->setUnpublished();
    $move = $this->move($node);
    $this->assertSame('/projects/phase-two', $move['to']);
    $this->assertSame('info', $move['tone']);
    $this->assertSame([], $move['lines']);

    // The markup: heading by host, the diff, and the button.
    $html = (string) $this->container->get('renderer')->renderInIsolation(PageUrlField::notes($this->pageUrl()->describe($node))['callouts']);
    $this->assertStringContainsString('Address changes when saved', $html);
    $this->assertStringContainsString('neo-seo-url-address__end', $html);
    $this->assertStringContainsString('data-neo-seo-url-keep=', $html);
    $alchemist = (string) $this->container->get('renderer')->renderInIsolation(PageUrlField::notes($this->pageUrl()->describe($node), TRUE)['callouts']);
    $this->assertStringContainsString('Address changes when published', $alchemist);
  }

  /**
   * Automatic | Custom: for who may set a custom URL, or hand one back.
   *
   * Offered to editors who may set a custom URL, and to anyone on a page that
   * has one, who may hand it back to the pattern.
   */
  public function testModeChoice(): void {
    $node = $this->node('project', 'Plant Expansion');
    $editor = ['access content', 'bypass node access', 'edit neo_seo overrides'];
    $slugOnly = $this->createUser([...$editor, 'create url aliases']);
    $admin = $this->createUser([...$editor, 'administer url aliases']);

    $this->setCurrentUser($slugOnly);
    $this->assertFalse($this->urlField($this->form($node))['url_custom']['#access'], 'No choice without a custom URL to choose.');

    $this->setCurrentUser($admin);
    $choice = $this->urlField($this->form($this->reload($node)))['url_custom'];
    $this->assertSame('radios', $choice['#type']);
    $this->assertSame(['Automatic', 'Custom'], array_map('strval', $choice['#options']));
    $this->assertSame(0, $choice['#default_value']);
    $this->assertSame('neo_seo[0][url_custom]', $choice['#name']);

    $this->pageUrl()->apply($node, $admin, ['url_custom' => TRUE, 'url_alias' => '/our-plant']);
    $node->save();

    // A locked custom URL: Custom chosen, the address read-only.
    $this->setCurrentUser($slugOnly);
    $field = $this->urlField($this->form($this->reload($node)));
    $this->assertNotFalse($field['url_custom']['#access']);
    $this->assertSame(1, $field['url_custom']['#default_value']);
    $this->assertTrue($field['url_alias']['#disabled']);
    $this->assertStringContainsString('Choose Automatic', (string) $field['url_alias']['#description']);

    // Choosing Automatic and saving hands the page back.
    $formState = (new FormState())->setValues([
      'title' => [['value' => 'Plant Expansion']],
      SeoOverrides::FIELD => [['url_custom' => '0', 'slug' => '']],
      'op' => 'Save',
    ]);
    $formObject = $this->container->get('entity_type.manager')->getFormObject('node', 'default')->setEntity($this->reload($node));
    $this->container->get('form_builder')->submitForm($formObject, $formState);
    $this->assertSame([], $formState->getErrors());
    $this->assertSame('/projects/plant-expansion', $this->alias($this->reload($node)));
  }

  /**
   * The URL field follows core's URL alias permission, not the overrides'.
   */
  public function testUrlFieldFollowsTheAliasPermission(): void {
    $node = $this->node('project', 'Plant Expansion');
    $node->set(SeoOverrides::FIELD, ['slug' => 'phase-one', 'keyphrase' => 'plant'])->save();

    // May change URLs, not SEO overrides: the URL field, and its slug saves.
    $urlsOnly = $this->createUser(['access content', 'bypass node access', 'create url aliases']);
    $this->setCurrentUser($urlsOnly);
    $form = $this->form($this->reload($node));
    $this->assertArrayHasKey('neo_seo_url', $form);
    $this->assertFalse($form['path']['#access']);
    $this->submit($this->reload($node), ['slug' => 'phase-two']);
    $saved = $this->reload($node);
    $this->assertSame('phase-two', SeoOverrides::of($saved)['slug']);
    $this->assertSame('plant', SeoOverrides::of($saved)['keyphrase'], 'The overrides they may not edit are kept.');
    $this->assertSame('/projects/phase-two', $this->alias($saved));

    // May edit SEO overrides, not URLs: no URL field, and the slug is kept.
    $seoOnly = $this->createUser(['access content', 'bypass node access', 'edit neo_seo overrides']);
    $this->setCurrentUser($seoOnly);
    $form = $this->form($this->reload($node));
    $this->assertArrayNotHasKey('neo_seo_url', $form);
    $this->assertFalse($form['path']['#access'], 'Nor core’s widget, which they may not use either.');
    $this->submit($this->reload($node), ['keyphrase' => 'expansion']);
    $saved = $this->reload($node);
    $this->assertSame('phase-two', SeoOverrides::of($saved)['slug'], 'Saving the overrides keeps the slug.');
    $this->assertSame('expansion', SeoOverrides::of($saved)['keyphrase']);

    // Nor may they change the URL through drafted values.
    $this->pageUrl()->apply($saved, $seoOnly, ['url_custom' => FALSE]);
    $noAliases = $this->createUser(['administer neo_seo']);
    $this->pageUrl()->apply($saved, $noAliases, ['url_custom' => TRUE, 'url_alias' => '/elsewhere']);
    $saved->save();
    $this->assertSame('/projects/phase-two', $this->alias($this->reload($saved)));
  }

  /**
   * The URL's own section in the edit form; open and copy once it is saved.
   */
  public function testUrlSectionAndActions(): void {
    $form = $this->form($this->node('project', 'Plant Expansion'));
    $section = $form['neo_seo_url'];
    $this->assertSame('details', $section['#type']);
    $this->assertSame('URL', (string) $section['#title']);
    $this->assertFalse($section['#open'], 'It starts closed.');
    $this->assertSame('advanced', $section['#group']);
    $this->assertSame(30, $section['#weight'], 'Where core’s URL alias section was.');
    $this->assertArrayNotHasKey('slug', $form[SeoOverrides::FIELD]['widget'][0]['search_social'], 'Not in Search & social.');
    $this->assertSame('neo_seo[0][slug]', $section['url_field']['slug']['#name'], 'Named as the overrides are.');

    $actions = $section['url_field']['actions'];
    $this->assertNotFalse($actions['#access']);
    $this->assertStringEndsWith('/projects/plant-expansion', $actions['#context']['url']);

    $new = $this->urlField($this->form(Node::create(['type' => 'project'])));
    $this->assertFalse($new['actions']['#access'], 'A new page has no address to open or copy.');
  }

  /**
   * The Alchemist panel has no sections: the URL has a heading of its own.
   *
   * Under the previews and above the title, in a search result's order.
   */
  public function testPanelUrlHeading(): void {
    $form = $this->container->get('form_builder')->getForm(SeoPanelForm::class, $this->node('project', 'Plant Expansion'), NULL);
    $element = $form[SeoOverrides::FIELD][0];
    $this->assertArrayNotHasKey('url', $element, 'No sections.');
    $field = $element['url_field'];
    $this->assertSame('URL', (string) $field['heading']['#value']);
    $this->assertGreaterThan($element['panel']['#weight'], $field['#weight'], 'Under the previews.');
    $this->assertLessThan($element['title']['#weight'] ?? 0, $field['#weight'], 'Above the title.');
    $this->assertSame('neo_seo[0][slug]', $field['slug']['#name']);
  }

  /**
   * The bundle form's URL settings are a URL section of their own.
   */
  public function testBundleUrlSection(): void {
    $this->setCurrentUser($this->createUser(['administer content types', 'administer neo_seo']));
    $formObject = $this->container->get('entity_type.manager')->getFormObject('node_type', 'edit')->setEntity(NodeType::load('project'));
    $formState = new FormState();
    $form = $this->container->get('form_builder')->buildForm($formObject, $formState);
    $this->assertSame('URL', (string) $form['neo_seo_url']['#title']);
    $this->assertSame('additional_settings', $form['neo_seo_url']['#group']);
    $this->assertArrayNotHasKey('custom_url', $form['neo_seo'], 'No longer in the SEO section.');
    $this->assertSame('neo_seo[custom_url]', $form['neo_seo_url']['custom_url']['#name'], 'Still the mapping’s value.');
    $this->assertStringContainsString('[neo:slug]', (string) $form['neo_seo_url']['url_patterns']['list']['#items'][0]['#context']['pattern']);

    $type = NodeType::load('project');
    $formState = (new FormState())->setValue('neo_seo', ['custom_url' => 1]);
    BundleMappingHooks::buildBundleEntity('node_type', $type, $form, $formState);
    $this->assertTrue($type->getThirdPartySetting('neo_seo', 'custom_url'));
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
   * The URL field of a node's built edit form.
   */
  protected function urlField(array $form): array {
    return $form['neo_seo_url']['url_field'];
  }

  /**
   * Every line of the callouts describe() gives a page, as text.
   *
   * @return string[]
   *   The lines.
   */
  protected function notes(NodeInterface $node): array {
    $lines = array_merge(...array_column($this->pageUrl()->describe($node)['callouts'], 'lines'));
    return array_map('html_entity_decode', array_column($lines, 'text'));
  }

  /**
   * The move callout describe() gives a page, if it moves.
   */
  protected function move(NodeInterface $node): ?array {
    foreach ($this->pageUrl()->describe($node)['callouts'] as $callout) {
      if ($callout['kind'] === 'move') {
        return $callout;
      }
    }
    return NULL;
  }

  /**
   * Submits a node's edit form with these overrides values.
   */
  protected function submit(NodeInterface $node, array $overrides): void {
    $formObject = $this->container->get('entity_type.manager')->getFormObject('node', 'default')->setEntity($node);
    $formState = (new FormState())->setValues([
      'title' => [['value' => $node->label()]],
      SeoOverrides::FIELD => [$overrides],
      'op' => 'Save',
    ]);
    $this->container->get('form_builder')->submitForm($formObject, $formState);
    $this->assertSame([], $formState->getErrors());
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
