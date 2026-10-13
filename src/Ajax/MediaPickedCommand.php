<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Ajax;

use Drupal\Core\Ajax\CommandInterface;

/**
 * Puts a media item chosen in the media library into its picker.
 *
 * The browser half (neo_seo/media_picker) sets the picker's value and fires
 * real input and change events, so a script listening for edits — the SEO
 * panel's hosts — hears it like any other.
 */
class MediaPickedCommand implements CommandInterface {

  /**
   * Constructs the command.
   *
   * @param string $picker
   *   The picker's key (data-neo-seo-media).
   * @param string $mediaId
   *   The chosen media item's id.
   */
  public function __construct(
    protected string $picker,
    protected string $mediaId,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function render() {
    return ['command' => 'neoSeoMediaPicked', 'picker' => $this->picker, 'media' => $this->mediaId];
  }

}
