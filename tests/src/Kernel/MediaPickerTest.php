<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_seo\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\media_library\MediaLibraryState;
use Drupal\neo_seo\Element\MediaPicker;
use Drupal\neo_seo\SeoOverrides;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * The share image and logo are chosen in the media library.
 *
 * The neo_seo_media element is a hidden id beside a preview and a link that
 * opens the library; the neo_seo.media_library_opener opener answers the
 * library, and exists only while media_library is enabled.
 */
#[Group('neo_seo')]
class MediaPickerTest extends NeoSeoKernelTestBase {

  use MediaTypeCreationTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node', 'text', 'filter', 'views', 'media_library'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('node');
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('system', ['sequences']);
    $this->installConfig(['node', 'filter', 'media', 'image']);
    $this->config('neo_seo.settings')->set('override_entity_types', ['node'])->save();
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    $this->createMediaType('image', ['id' => 'image']);
    $this->createMediaType('file', ['id' => 'document']);
    $this->setUpCurrentUser([], ['access content', 'bypass node access', 'edit neo_seo overrides']);
  }

  /**
   * An image media item with a small file.
   */
  protected function imageMedia(): Media {
    imagepng(imagecreatetruecolor(40, 20), 'public://share.png');
    $file = File::create(['uri' => 'public://share.png']);
    $file->save();
    $media = Media::create([
      'bundle' => 'image',
      'name' => 'Share card',
      'field_media_image' => ['target_id' => $file->id(), 'alt' => 'Share'],
    ]);
    $media->save();
    return $media;
  }

  /**
   * The share image is the picker, with the chosen media shown.
   */
  public function testTheShareImageIsThePicker(): void {
    $media = $this->imageMedia();
    $node = Node::create(['type' => 'page', 'title' => 'About', SeoOverrides::FIELD => ['image' => $media->id()]]);
    $node->save();
    $formObject = $this->container->get('entity_type.manager')->getFormObject('node', 'default')->setEntity($node);
    $formState = new FormState();
    $form = $this->container->get('form_builder')->buildForm($formObject, $formState);
    $element = $form[SeoOverrides::FIELD]['widget'][0]['search_social']['image'];

    $this->assertSame('neo_seo_media', $element['#type']);
    $this->assertSame((string) $media->id(), $element['#value']);
    $open = $element['#field_suffix']['actions']['open'];
    $query = $open['#url']->getOption('query');
    $this->assertSame(MediaPicker::OPENER, $query['media_library_opener_id']);
    $this->assertSame(['image'], array_values($query['media_library_allowed_types']), 'Image media only.');
    $this->assertSame('Share card', $element['#field_suffix']['preview']['media']['#context']['name']);
  }

  /**
   * The value is a media id or nothing.
   */
  public function testTheValueIsAnIdOrNothing(): void {
    $element = ['#default_value' => '12'];
    $state = new FormState();
    $this->assertSame('12', MediaPicker::valueCallback($element, FALSE, $state));
    $this->assertSame('34', MediaPicker::valueCallback($element, '34', $state));
    $this->assertSame('', MediaPicker::valueCallback($element, 'Share card (34)', $state));
    $this->assertSame('', MediaPicker::valueCallback($element, '', $state));
  }

  /**
   * The opener lets SEO editors in and answers with the chosen media.
   */
  public function testTheOpenerAnswersTheLibrary(): void {
    $this->assertTrue($this->container->has(MediaPicker::OPENER));
    $opener = $this->container->get(MediaPicker::OPENER);
    $state = MediaLibraryState::create(MediaPicker::OPENER, ['image'], 'image', 1, ['neo_seo_media' => 'edit-image']);

    $this->assertTrue($opener->checkAccess($state, $this->container->get('current_user'))->isAllowed());
    $outsider = $this->createUser(['access content']);
    $this->assertFalse($opener->checkAccess($state, $outsider)->isAllowed());

    $media = $this->imageMedia();
    $commands = $opener->getSelectionResponse($state, [$media->id()])->getCommands();
    $byName = array_column($commands, NULL, 'command');
    $this->assertSame(['command' => 'neoSeoMediaPicked', 'picker' => 'edit-image', 'media' => (string) $media->id()], $byName['neoSeoMediaPicked']);
    $this->assertSame('[data-neo-seo-media-preview="edit-image"]', $byName['insert']['selector']);
    $this->assertStringContainsString('Share card', (string) $byName['insert']['data']);
    $this->assertArrayHasKey('closeDialog', $byName);
  }

}
