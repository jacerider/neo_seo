<?php

declare(strict_types=1);

namespace Drupal\neo_seo\MediaLibrary;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\CloseDialogCommand;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\media\MediaInterface;
use Drupal\media_library\MediaLibraryOpenerInterface;
use Drupal\media_library\MediaLibraryState;
use Drupal\neo_seo\Ajax\MediaPickedCommand;
use Drupal\neo_seo\Element\MediaPicker;

/**
 * Answers the media library for neo_seo's media pickers.
 *
 * Registered only while media_library is enabled (NeoSeoServiceProvider).
 */
class MediaPickerOpener implements MediaLibraryOpenerInterface {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   *
   * Whoever may set a share image or the site logo may browse for one. The
   * state's hash, checked before this runs, ties the request to a picker
   * neo_seo rendered.
   */
  public function checkAccess(MediaLibraryState $state, AccountInterface $account) {
    $parameters = $state->getOpenerParameters();
    if (empty($parameters['neo_seo_media'])) {
      return AccessResult::forbidden('The neo_seo_media parameter is missing.')->addCacheableDependency($state);
    }
    return AccessResult::allowedIfHasPermissions($account, ['edit neo_seo overrides', 'administer neo_seo'], 'OR');
  }

  /**
   * {@inheritdoc}
   */
  public function getSelectionResponse(MediaLibraryState $state, array $selected_ids) {
    $picker = (string) $state->getOpenerParameters()['neo_seo_media'];
    $id = (string) reset($selected_ids);
    $media = $id !== '' ? $this->entityTypeManager->getStorage('media')->load($id) : NULL;
    $response = new AjaxResponse();
    if ($media instanceof MediaInterface) {
      $selector = '[data-neo-seo-media-preview="' . $picker . '"]';
      $response->addCommand(new HtmlCommand($selector, MediaPicker::preview($media)));
      $response->addCommand(new MediaPickedCommand($picker, (string) $media->id()));
    }
    $response->addCommand(new CloseDialogCommand('#drupal-modal'));
    return $response;
  }

}
