<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_seo\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\neo_seo\Entity\SeoDefaults;
use Drupal\neo_seo\Hook\BundleMappingHooks;
use Drupal\neo_seo\Hook\NeoSeoHooks;
use Drupal\neo_seo\SeoOverrides;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * Bundle mapping: page type, article type, image, dates and breadcrumb.
 */
#[Group('neo_seo')]
class BundleMappingTest extends NeoSeoKernelTestBase {

  use MediaTypeCreationTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node', 'text', 'filter', 'datetime', 'link'];

  /**
   * The insight content type.
   */
  protected NodeType $insight;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('node');
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['node', 'filter', 'media', 'image']);
    $this->config('neo_seo.settings')->set('override_entity_types', ['node'])->save();

    $this->insight = NodeType::create(['type' => 'insight', 'name' => 'Insight']);
    $this->insight->save();
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();

    $mediaType = $this->createMediaType('image');
    FieldStorageConfig::create([
      'field_name' => 'field_media',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'media'],
    ])->save();
    FieldConfig::create(['field_name' => 'field_media', 'entity_type' => 'node', 'bundle' => 'insight'])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_date',
      'entity_type' => 'node',
      'type' => 'datetime',
      'settings' => ['datetime_type' => 'date'],
    ])->save();
    FieldConfig::create(['field_name' => 'field_date', 'entity_type' => 'node', 'bundle' => 'insight'])->save();

    (SeoDefaults::load('global') ?? SeoDefaults::create(['id' => 'global', 'label' => 'Global']))->setValues([
      'title' => '[node:title] | [site:name]',
      'image' => '/template.png',
      'og_type' => 'website',
    ])->save();

    $this->mediaTypeId = $mediaType->id();

    // People: a profile-page bundle, and a reference to it on insights.
    $person = NodeType::create(['type' => 'person', 'name' => 'Person']);
    $person->setThirdPartySetting('neo_seo', 'page_type', 'ProfilePage')
      ->setThirdPartySetting('neo_seo', 'job_title_field', 'field_job_title')
      ->setThirdPartySetting('neo_seo', 'same_as_field', 'field_linkedin')
      ->save();
    foreach (['field_job_title' => 'string', 'field_linkedin' => 'link'] as $name => $type) {
      FieldStorageConfig::create(['field_name' => $name, 'entity_type' => 'node', 'type' => $type])->save();
      FieldConfig::create(['field_name' => $name, 'entity_type' => 'node', 'bundle' => 'person'])->save();
    }
    FieldStorageConfig::create([
      'field_name' => 'field_related_people',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'cardinality' => -1,
      'settings' => ['target_type' => 'node'],
    ])->save();
    FieldConfig::create(['field_name' => 'field_related_people', 'entity_type' => 'node', 'bundle' => 'insight'])->save();
    $this->installSchema('system', ['sequences']);
    $this->setUpCurrentUser([], ['access content']);
  }

  /**
   * Creates a person.
   */
  protected function person(string $name, bool $published = TRUE): NodeInterface {
    $node = Node::create([
      'type' => 'person',
      'title' => $name,
      'status' => $published,
      'field_job_title' => 'Engineer',
      'field_linkedin' => ['uri' => 'https://www.linkedin.com/in/' . strtolower($name)],
    ]);
    $node->save();
    return $node;
  }

  /**
   * The image media type id.
   */
  protected string $mediaTypeId;

  /**
   * Stores a mapping on the insight type.
   */
  protected function map(array $settings): void {
    foreach ($settings as $key => $value) {
      $this->insight->setThirdPartySetting('neo_seo', $key, $value);
    }
    $this->insight->save();
  }

  /**
   * Creates an insight.
   */
  protected function insight(array $values = []): NodeInterface {
    $node = Node::create($values + ['type' => 'insight', 'title' => 'Healing gardens', 'created' => 1700000000]);
    $node->save();
    return $node;
  }

  /**
   * The graph and head of a node's page.
   *
   * @return array{0: array, 1: array<string, string|null>}
   *   The JSON-LD document and the head tags' content keyed by attachment key.
   */
  protected function page(NodeInterface $node): array {
    $this->pushRoute('entity.node.canonical', ['node' => $node], '/node/' . $node->id());
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
    return [$document, $tags];
  }

  /**
   * Creates an image media item.
   */
  protected function image(string $name, int $width = 40, int $height = 20): Media {
    imagepng(imagecreatetruecolor($width, $height), "public://$name.png");
    $file = File::create(['uri' => "public://$name.png"]);
    $file->save();
    $media = Media::create([
      'bundle' => $this->mediaTypeId,
      'name' => $name,
      'field_media_image' => ['target_id' => $file->id(), 'alt' => "$name alt"],
    ]);
    $media->save();
    return $media;
  }

  /**
   * An unmapped bundle is a plain WebPage with no Article.
   */
  public function testUnmappedBundleIsPlainWebPage(): void {
    [$document, $tags] = $this->page($this->insight());
    $this->assertGraphReferencesResolve($document);
    $page = $this->assertGraphNode($document, $tags['neo_seo_canonical']);
    $this->assertSame('WebPage', $page['@type']);
    $this->assertNotContains($tags['neo_seo_canonical'] . '#article', $this->graphIds($document));
    $this->assertSame('website', $tags['neo_seo_og_type']);
  }

  /**
   * A mapped insight: Article, primary image from its field, mapped dates.
   */
  public function testMappedInsight(): void {
    $this->map([
      'article_type' => 'Article',
      'image_field' => 'field_media',
      'published_field' => 'field_date',
    ]);
    $media = $this->image('garden', 120, 80);
    $node = $this->insight(['field_media' => $media->id(), 'field_date' => '2026-09-15']);

    [$document, $tags] = $this->page($node);
    $canonical = $tags['neo_seo_canonical'];
    $this->assertGraphReferencesResolve($document);

    $article = $this->assertGraphNode($document, $canonical . '#article');
    $this->assertSame('Article', $article['@type']);
    $this->assertSame('Healing gardens', $article['headline']);
    $this->assertSame(['@id' => $canonical], $article['isPartOf']);
    $this->assertSame(['@id' => $canonical], $article['mainEntityOfPage']);
    $this->assertSame(['@id' => 'http://localhost/#organization'], $article['publisher']);
    $this->assertSame(['@id' => $canonical . '#primaryimage'], $article['image']);
    $this->assertSame('2026-09-15', $article['datePublished'], 'A date-only field stays a date.');
    $this->assertArrayHasKey('dateModified', $article);

    $image = $this->assertGraphNode($document, $canonical . '#primaryimage');
    $this->assertStringEndsWith('/garden.png', $image['contentUrl']);
    $this->assertSame(120, $image['width']);
    $this->assertSame('garden alt', $image['caption']);
    $this->assertSame($image['contentUrl'], $tags['neo_seo_og_image'], 'The share image is the primary image.');

    $page = $this->assertGraphNode($document, $canonical);
    $this->assertSame(['@id' => $canonical . '#primaryimage'], $page['primaryImageOfPage']);
    $this->assertSame(['@id' => $canonical . '#breadcrumb'], $page['breadcrumb']);

    $this->assertSame('article', $tags['neo_seo_og_type'], 'An article is an article whatever the template says.');
    $this->assertSame($article['datePublished'], $tags['neo_seo_article_published_time']);

    $trail = $this->assertGraphNode($document, $canonical . '#breadcrumb');
    $last = end($trail['itemListElement']);
    $this->assertSame($canonical, $last['item']);
    $this->assertSame(count($trail['itemListElement']), $last['position']);
  }

  /**
   * An editor's override wins over the mapped image field.
   */
  public function testOverrideImageBeatsMappedField(): void {
    $this->map(['image_field' => 'field_media']);
    $node = $this->insight([
      'field_media' => $this->image('field')->id(),
      SeoOverrides::FIELD => ['image' => $this->image('override')->id()],
    ]);
    [, $tags] = $this->page($node);
    $this->assertStringEndsWith('/override.png', $tags['neo_seo_og_image']);
  }

  /**
   * An editor may choose only the types the bundle allows.
   */
  public function testEditorChoicesAreLimited(): void {
    $this->map([
      'page_type' => 'WebPage',
      'page_types' => ['AboutPage'],
      'article_type' => 'None',
      'article_types' => ['BlogPosting'],
    ]);

    $allowed = $this->insight([SeoOverrides::FIELD => ['page_type' => 'AboutPage', 'article_type' => 'BlogPosting']]);
    [$document, $tags] = $this->page($allowed);
    $this->assertSame(['WebPage', 'AboutPage'], $this->assertGraphNode($document, $tags['neo_seo_canonical'])['@type']);
    $this->assertSame('BlogPosting', $this->assertGraphNode($document, $tags['neo_seo_canonical'] . '#article')['@type']);

    $refused = $this->insight([SeoOverrides::FIELD => ['page_type' => 'ContactPage', 'article_type' => 'NewsArticle']]);
    [$document, $tags] = $this->page($refused);
    $this->assertSame('WebPage', $this->assertGraphNode($document, $tags['neo_seo_canonical'])['@type'], 'A type the bundle does not allow is ignored.');
    $this->assertNotContains($tags['neo_seo_canonical'] . '#article', $this->graphIds($document));
  }

  /**
   * A profile page is about its person, under the person's own id.
   */
  public function testProfilePage(): void {
    $ada = $this->person('Ada');
    [$document, $tags] = $this->page($ada);
    $this->assertGraphReferencesResolve($document);
    $page = $this->assertGraphNode($document, $tags['neo_seo_canonical']);
    $this->assertSame(['WebPage', 'ProfilePage'], $page['@type']);
    $this->assertSame('profile', $tags['neo_seo_og_type']);

    $id = 'http://localhost/#/schema/person/' . $ada->uuid();
    $this->assertSame(['@id' => $id], $page['mainEntity']);
    $person = $this->assertGraphNode($document, $id);
    $this->assertSame('Person', $person['@type']);
    $this->assertSame('Ada', $person['name']);
    $this->assertSame('Engineer', $person['jobTitle']);
    $this->assertSame(['https://www.linkedin.com/in/ada'], $person['sameAs']);
    $this->assertSame($tags['neo_seo_canonical'], $person['url']);
    $this->assertSame(['@id' => 'http://localhost/#organization'], $person['worksFor']);
  }

  /**
   * Authors are people, under the same ids as on their own pages.
   */
  public function testAuthors(): void {
    $this->map(['article_type' => 'Article', 'author_field' => 'field_related_people']);
    $ada = $this->person('Ada');
    $grace = $this->person('Grace');
    $hidden = $this->person('Hidden', FALSE);
    $node = $this->insight(['field_related_people' => [$ada->id(), $grace->id(), $hidden->id(), $ada->id()]]);

    [$document, $tags] = $this->page($node);
    $this->assertGraphReferencesResolve($document);
    $article = $this->assertGraphNode($document, $tags['neo_seo_canonical'] . '#article');
    $this->assertSame([
      ['@id' => 'http://localhost/#/schema/person/' . $ada->uuid()],
      ['@id' => 'http://localhost/#/schema/person/' . $grace->uuid()],
    ], $article['author'], 'Visible people, once each, in field order.');
    $this->assertGraphNode($document, 'http://localhost/#/schema/person/' . $grace->uuid());
    $this->assertNotContains('http://localhost/#/schema/person/' . $hidden->uuid(), $this->graphIds($document), 'An unpublished person is left out.');

    // The same person on their own page has the same id.
    [$profile] = $this->page($ada);
    $this->assertGraphNode($profile, 'http://localhost/#/schema/person/' . $ada->uuid());
  }

  /**
   * Without authors the Article has only its publisher.
   */
  public function testNoAuthors(): void {
    $this->map(['article_type' => 'Article', 'author_field' => 'field_related_people']);
    [$document, $tags] = $this->page($this->insight());
    $article = $this->assertGraphNode($document, $tags['neo_seo_canonical'] . '#article');
    $this->assertArrayNotHasKey('author', $article);
    $this->assertArrayHasKey('publisher', $article);
  }

  /**
   * The bundle form shows the section and stores only what differs.
   */
  public function testBundleForm(): void {
    $this->setUpCurrentUser([], ['administer content types', 'administer neo_seo']);
    $form = $this->container->get('entity.form_builder')->getForm($this->insight, 'edit');
    $this->assertArrayHasKey('neo_seo', $form);
    $this->assertArrayHasKey('field_media', $form['neo_seo']['image_field']['#options']);
    $this->assertArrayHasKey('field_date', $form['neo_seo']['published_field']['#options']);
    $this->assertArrayHasKey('field_related_people', $form['neo_seo']['author_field']['#options']);

    $formState = (new FormState())->setValue('neo_seo', [
      'page_type' => 'WebPage',
      'page_types' => ['AboutPage' => 'AboutPage', 'ItemPage' => 0],
      'article_type' => 'Article',
      'article_types' => [],
      'image_field' => 'field_media',
      'published_field' => '',
      'modified_field' => '',
    ]);
    BundleMappingHooks::buildBundleEntity('node_type', $this->insight, $form, $formState);
    $this->insight->save();
    $this->assertSame([
      'page_types' => ['AboutPage'],
      'article_type' => 'Article',
      'image_field' => 'field_media',
    ], NodeType::load('insight')->getThirdPartySettings('neo_seo'), 'Defaults are not stored, and the mapping exports with the bundle.');
  }

}
