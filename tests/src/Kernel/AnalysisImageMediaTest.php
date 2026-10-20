<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_seo\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\media\MediaInterface;
use Drupal\neo_seo_analysis\Hook\MediaFormHooks;
use Drupal\neo_seo_analysis\ImageMedia;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;

/**
 * The alt text check's way to an image's media item, and its modal save.
 *
 * @see \Drupal\neo_seo_analysis\ImageMedia
 * @see \Drupal\neo_seo_analysis\Hook\MediaFormHooks
 */
#[Group('neo_seo')]
class AnalysisImageMediaTest extends NeoSeoKernelTestBase {

  use MediaTypeCreationTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node', 'text', 'filter', 'views', 'neo_seo_panel', 'neo_seo_analysis'];

  /**
   * The media item holding the image.
   */
  protected MediaInterface $media;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installSchema('file', ['file_usage']);
    $this->installConfig(['media', 'image']);
    $type = $this->createMediaType('image', ['id' => 'image']);
    $file = File::create(['uri' => 'public://2026-07/civic-building.jpg']);
    $file->save();
    $this->media = Media::create([
      'bundle' => $type->id(),
      'name' => 'Civic building',
      $type->getSource()->getConfiguration()['source_field'] => ['target_id' => $file->id(), 'alt' => 'Civic Building'],
    ]);
    $this->media->save();
    // User 1 is not the editor: it would bypass every access check.
    $this->createUser();
  }

  /**
   * An image URL leads to its file: itself, or through a derivative.
   */
  public function testUris(): void {
    $lookup = $this->lookup();
    $public = $this->container->get('stream_wrapper_manager')->getViaScheme('public')->getDirectoryPath();
    $this->assertSame(
      ['public://2026-07/civic-building.jpg', 'public://2026-07/civic-building.jpg.avif'],
      $lookup->uris('https://example.com/' . $public . '/styles/w600/public/2026-07/civic-building.jpg.avif?itok=x'),
      'A converting style\'s added format is taken off first.',
    );
    $this->assertSame(['public://2026-07/civic-building.jpg'], $lookup->uris('/' . $public . '/2026-07/civic-building.jpg'));
    $this->assertSame(['private://a/b.png'], $lookup->uris('/system/files/a/b.png'));
    $this->assertSame([], $lookup->uris('/themes/front/logo.svg'), 'Not a managed file.');
  }

  /**
   * The media item, and its edit form for an editor who may change it.
   */
  public function testResolve(): void {
    $public = $this->container->get('stream_wrapper_manager')->getViaScheme('public')->getDirectoryPath();
    $derivative = '/' . $public . '/styles/w600/public/2026-07/civic-building.jpg.webp?itok=x';

    $this->setUpCurrentUser([], ['update any media', 'view media']);
    $found = $this->lookup()->resolve([$derivative, '/themes/front/logo.svg']);
    $this->assertSame((int) $this->media->id(), $found[$derivative]['media']);
    $this->assertSame('/media/' . $this->media->id() . '/edit?neo_seo_media=1', $found[$derivative]['edit']);
    $this->assertNull($found['/themes/front/logo.svg'], 'No media item holds it.');

    $this->setUpCurrentUser([], ['view media']);
    $found = $this->lookup()->resolve([$derivative]);
    $this->assertSame((int) $this->media->id(), $found[$derivative]['media']);
    $this->assertNull($found[$derivative]['edit'], 'Found, but not editable by this editor.');
  }

  /**
   * Opened from the panel in a modal, the media form saves over AJAX.
   */
  public function testModalSave(): void {
    $this->setUpCurrentUser([], ['update any media', 'view media', 'delete any media']);

    $form = $this->mediaForm('/media/1/edit?neo_seo_media=1&_wrapper_format=drupal_modal');
    $this->assertSame([MediaFormHooks::class, 'ajaxSave'], $form['actions']['submit']['#ajax']['callback']);
    $this->assertFalse($form['actions']['delete']['#access'], 'Only Save: the modal is for the alt text.');

    $form = $this->mediaForm('/media/1/edit?neo_seo_media=1');
    $this->assertArrayNotHasKey('#ajax', $form['actions']['submit'], 'Not in a dialog: the page form.');
    $form = $this->mediaForm('/media/1/edit?_wrapper_format=drupal_modal');
    $this->assertArrayNotHasKey('#ajax', $form['actions']['submit'], 'Not from the panel: untouched.');

    $formState = new FormState();
    $formState->setFormObject($this->container->get('entity_type.manager')->getFormObject('media', 'edit')->setEntity($this->media));
    $form = [];
    $commands = MediaFormHooks::ajaxSave($form, $formState)->getCommands();
    $this->assertSame(['closeDialog', 'neoSeoMediaSaved'], array_column($commands, 'command'));
    $this->assertSame((int) $this->media->id(), $commands[1]['media']);
  }

  /**
   * The lookup, built for the current request and user.
   */
  protected function lookup(): ImageMedia {
    return $this->container->get('neo_seo_analysis.image_media');
  }

  /**
   * The media item's edit form, as built for a request.
   */
  protected function mediaForm(string $uri): array {
    $request = Request::create($uri);
    $request->setSession($this->container->get('session'));
    $this->container->get('request_stack')->push($request);
    try {
      $formObject = $this->container->get('entity_type.manager')->getFormObject('media', 'edit')->setEntity($this->media);
      return $this->container->get('form_builder')->getForm($formObject);
    }
    finally {
      $this->container->get('request_stack')->pop();
    }
  }

}
