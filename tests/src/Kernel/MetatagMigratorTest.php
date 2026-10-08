<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_seo\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\metatag\Entity\MetaTagDefaults;
use Drupal\neo_seo\Entity\SeoDefaults;
use Drupal\neo_seo\SeoOverrides;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\Group;

/**
 * Converting a metatag setup into neo_seo's.
 */
#[Group('neo_seo')]
class MetatagMigratorTest extends NeoSeoKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node', 'text', 'filter', 'metatag'];

  /**
   * Schema tags come from schema_metatag, which these tests do not install.
   *
   * @var bool
   */
  protected $strictConfigSchema = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['node', 'filter']);
    NodeType::create(['type' => 'insight', 'name' => 'Insight'])->save();
    NodeType::create(['type' => 'person', 'name' => 'Person'])->save();

    $this->defaults('global', [
      'title' => '[neo:title] | [site:name]',
      'description' => '[neo:description]',
      'og_title' => '[neo:title]',
      'og_image' => '[neo:image]',
      'og_image_url' => '[neo:image]',
      'canonical_url' => '[current-page:url]',
      'og_type' => 'website',
      'dcterms_creator' => 'Westwood',
      'schema_organization_type' => 'Organization',
      'schema_organization_name' => '[site:name]',
      'schema_organization_telephone' => '[site:phone]',
      'schema_organization_same_as' => 'https://www.facebook.com/x,https://www.youtube.com/y',
      'schema_organization_address' => serialize([
        '@type' => 'PostalAddress',
        'streetAddress' => '[site:address:street_1]',
        'addressLocality' => 'Plano',
      ]),
      'schema_organization_logo' => serialize(['@type' => 'ImageObject', 'url' => '[neo:logo]']),
      'schema_web_site_name' => '[site:name]',
    ]);
    $this->defaults('node__insight', [
      'og_type' => 'article',
      'schema_article_type' => 'BlogPosting',
      'schema_article_author' => serialize(['@type' => 'Organization']),
    ]);
    $this->defaults('node__person', [
      'og_type' => 'profile',
      'schema_person_type' => 'Person',
      'schema_person_job_title' => '[node:field_job_title]',
    ]);
    $this->defaults('taxonomy_term__region', ['robots' => 'noindex, follow']);
  }

  /**
   * Creates a metatag defaults set.
   */
  protected function defaults(string $id, array $tags): void {
    MetaTagDefaults::create(['id' => $id, 'label' => $id, 'tags' => $tags])->save();
  }

  /**
   * The outcome reported for a tag of a set.
   */
  protected function outcome(array $plan, string $set, string $tag): ?string {
    foreach ($plan['report'] as $line) {
      if ($line['set'] === $set && $line['tag'] === $tag) {
        return $line['outcome'];
      }
    }
    return NULL;
  }

  /**
   * Every value is accounted for, and the right ones become neo_seo's.
   */
  public function testPlan(): void {
    $plan = $this->container->get('neo_seo.metatag_migrator')->plan();

    $this->assertSame([
      'title' => '[neo:title] | [site:name]',
      'description' => '[neo:description]',
      'social_title' => '[neo:title]',
      'image' => '[neo:image]',
      'og_type' => 'website',
    ], $plan['defaults']['global']);
    $this->assertSame(['robots' => 'noindex, follow'], $plan['defaults']['taxonomy_term__region']);
    $this->assertSame('derived', $this->outcome($plan, 'global', 'og_image_url'), 'A second image tag is not a second image.');
    $this->assertSame('derived', $this->outcome($plan, 'global', 'canonical_url'));
    $this->assertSame('unconverted', $this->outcome($plan, 'global', 'dcterms_creator'));

    $this->assertSame(['article_type' => 'BlogPosting'], $plan['mappings']['node:insight']);
    $this->assertSame('unconverted', $this->outcome($plan, 'node__insight', 'schema_article_author'));
    $this->assertSame(['page_type' => 'ProfilePage', 'job_title_field' => 'field_job_title'], $plan['mappings']['node:person']);

    $this->assertSame('[site:name]', $plan['identity']['name']);
    $this->assertSame('[site:phone]', $plan['identity']['telephone']);
    $this->assertSame(['https://www.facebook.com/x', 'https://www.youtube.com/y'], $plan['identity']['same_as']);
    $this->assertSame(['street' => '[site:address:street_1]', 'locality' => 'Plano'], $plan['identity']['address']);
    $this->assertSame('unconverted', $this->outcome($plan, 'global', 'schema_organization_logo'));
    $this->assertSame('derived', $this->outcome($plan, 'global', 'schema_web_site_name'));

    $tags = 0;
    foreach (MetaTagDefaults::loadMultiple() as $defaults) {
      $tags += count($defaults->get('tags'));
    }
    $this->assertCount($tags, $plan['report'], 'Every metatag value gets a report line.');
  }

  /**
   * Applying writes the plan, never over a neo_seo value, and only once.
   */
  public function testApplyConfig(): void {
    (SeoDefaults::load('global') ?? SeoDefaults::create(['id' => 'global', 'label' => 'Global']))
      ->setValues(['title' => 'Kept'])
      ->save();
    $this->config('neo_seo.settings')->set('identity.telephone', '555-0100')->save();
    $migrator = $this->container->get('neo_seo.metatag_migrator');

    $written = $migrator->applyConfig($migrator->plan());
    $this->assertNotEmpty($written);
    $global = SeoDefaults::load('global')->getValues();
    $this->assertSame('Kept', $global['title'], 'An existing template is kept.');
    $this->assertSame('[neo:description]', $global['description']);
    $this->assertSame('noindex, follow', SeoDefaults::load('taxonomy_term__region')->getValues()['robots']);
    $this->assertSame('BlogPosting', NodeType::load('insight')->getThirdPartySetting('neo_seo', 'article_type'));
    $this->assertSame('ProfilePage', NodeType::load('person')->getThirdPartySetting('neo_seo', 'page_type'));
    $this->assertSame('555-0100', $this->config('neo_seo.settings')->get('identity.telephone'), 'A configured identity value is kept.');
    $sameAs = $this->config('neo_seo.settings')->get('identity.same_as');
    $this->assertSame(['https://www.facebook.com/x', 'https://www.youtube.com/y'], $sameAs);

    $this->assertSame([], $migrator->applyConfig($migrator->plan()), 'A second run writes nothing.');
  }

  /**
   * Metatag field values become overrides.
   */
  public function testApplyContent(): void {
    FieldStorageConfig::create(['field_name' => 'field_metatags', 'entity_type' => 'node', 'type' => 'metatag'])->save();
    FieldConfig::create(['field_name' => 'field_metatags', 'entity_type' => 'node', 'bundle' => 'insight'])->save();
    $this->config('neo_seo.settings')->set('override_entity_types', ['node'])->save();
    $node = Node::create([
      'type' => 'insight',
      'title' => 'Gardens',
      'field_metatags' => json_encode(['title' => 'Custom title', 'robots' => 'noindex, nofollow', 'og_image' => '/x.png']),
    ]);
    $node->save();

    $migrator = $this->container->get('neo_seo.metatag_migrator');
    $this->assertSame(['node' => 1], $migrator->plan()['content']);
    $this->assertSame(1, $migrator->applyContent());
    $overrides = SeoOverrides::of(Node::load($node->id()));
    $this->assertSame('Custom title', $overrides['title']);
    $this->assertTrue((bool) $overrides['noindex']);
    $this->assertSame(0, $migrator->applyContent(), 'A second run changes nothing.');
  }

}
