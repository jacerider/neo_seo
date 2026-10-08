<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_seo\Kernel;

use Drupal\entity_test\Entity\EntityTest;
use Drupal\field\Entity\FieldConfig;
use Drupal\neo_alchemist\Entity\Component;
use Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure;
use Drupal\neo_seo\Graph\GraphBuilder;
use Drupal\neo_seo\Graph\PageContext;
use Drupal\Tests\neo_alchemist\Kernel\HybridFieldKernelTestBase;
use Drupal\Tests\neo_seo\Traits\GraphAssertionsTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * Components declare schema pieces in their .component.yml.
 */
#[Group('neo_seo')]
class ComponentPiecesTest extends HybridFieldKernelTestBase {

  use GraphAssertionsTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'token',
    'neo_seo',
    'neo_seo_alchemist_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'neo_seo']);
    Component::create([
      'id' => 'seo_video',
      'label' => 'seo_video',
      'description' => 'seo_video',
      'component' => 'neo_seo_alchemist_test:seo_video',
      'status' => TRUE,
    ])->save();
    // Custom mode: each entity owns its tree.
    $field = FieldConfig::loadByName('entity_test', 'entity_test', static::FIELD_NAME);
    $field->setSetting('allow_custom', TRUE);
    $field->setSetting('defaults', []);
    $field->save();
    $this->resetFieldCaches('seo_video');
    // A component is described only where it would render: where its host
    // entity can be seen.
    $this->installSchema('system', ['sequences']);
    $this->setUpCurrentUser([], ['view test entity']);
  }

  /**
   * Builds the graph of an entity's page.
   */
  protected function graph(EntityTest $entity): array {
    $context = new PageContext(
      siteUrl: 'https://example.com/',
      canonicalUrl: 'https://example.com/video-page',
      langcode: 'en',
      siteName: 'Acme',
      entity: $entity,
      title: 'Video page',
    );
    return $this->container->get(GraphBuilder::class)->build($context);
  }

  /**
   * An entity whose tree holds the given video props, keyed by uuid.
   */
  protected function entityWithVideos(array $videos): EntityTest {
    $tree = [];
    $props = [];
    foreach ($videos as $uuid => $values) {
      $tree[] = ['uuid' => $uuid, 'component' => 'seo_video'];
      $entry = ['status' => $values['status'] ?? TRUE, 'props' => []];
      foreach (['title', 'video_url', 'poster'] as $prop) {
        if (isset($values[$prop])) {
          $entry['props'][$prop] = ['ref' => 'string', 'value' => ['value' => $values[$prop]]];
        }
      }
      $props[$uuid] = $entry;
    }
    $entity = $this->createTestEntity();
    $entity->set(static::FIELD_NAME, [['tree' => [ComponentTreeStructure::ROOT_UUID => $tree], 'props' => $props]]);
    $entity->save();
    return $this->reloadEntity($entity);
  }

  /**
   * Two videos on a page become two VideoObjects, part of the page.
   */
  public function testTwoVideos(): void {
    $entity = $this->entityWithVideos([
      'v1' => [
        'title' => 'Site tour',
        'video_url' => 'https://cdn.example.com/tour.mp4',
        'poster' => 'https://cdn.example.com/tour.jpg',
      ],
      'v2' => [
        'title' => 'Interview',
        'video_url' => 'https://cdn.example.com/talk.mp4',
        'poster' => 'https://cdn.example.com/talk.jpg',
      ],
    ]);
    $document = $this->graph($entity);
    $this->assertGraphReferencesResolve($document);
    $tour = $this->assertGraphNode($document, 'https://example.com/video-page#videoobject-v1');
    $this->assertSame('VideoObject', $tour['@type']);
    $this->assertSame('Site tour', $tour['name']);
    $this->assertSame('https://cdn.example.com/tour.mp4', $tour['contentUrl']);
    $this->assertSame('https://cdn.example.com/tour.jpg', $tour['thumbnailUrl']);
    $this->assertSame(['@id' => 'https://example.com/video-page'], $tour['isPartOf']);
    $this->assertNotEmpty($tour['uploadDate'], 'A video is published with its page.');
    $this->assertGraphNode($document, 'https://example.com/video-page#videoobject-v2');
  }

  /**
   * Hidden components contribute nothing; incomplete ones are dropped.
   */
  public function testHiddenAndIncomplete(): void {
    $entity = $this->entityWithVideos([
      'hidden' => [
        'title' => 'Hidden',
        'video_url' => 'https://x/h.mp4',
        'poster' => 'https://x/h.jpg',
        'status' => FALSE,
      ],
      'noposter' => ['title' => 'No poster', 'video_url' => 'https://x/n.mp4'],
      'shown' => ['title' => 'Shown', 'video_url' => 'https://x/s.mp4', 'poster' => 'https://x/s.jpg'],
    ]);
    $ids = $this->graphIds($this->graph($entity));
    $this->assertNotContains('https://example.com/video-page#videoobject-hidden', $ids);
    $this->assertNotContains('https://example.com/video-page#videoobject-noposter', $ids, 'A VideoObject without a thumbnail is left out.');
    $this->assertContains('https://example.com/video-page#videoobject-shown', $ids);
  }

  /**
   * A title-less video is named for its page.
   */
  public function testNameFallsBackToThePage(): void {
    $entity = $this->entityWithVideos([
      'v1' => ['video_url' => 'https://x/v.mp4', 'poster' => 'https://x/v.jpg'],
    ]);
    $video = $this->assertGraphNode($this->graph($entity), 'https://example.com/video-page#videoobject-v1');
    $this->assertSame('Video page', $video['name']);
  }

}
