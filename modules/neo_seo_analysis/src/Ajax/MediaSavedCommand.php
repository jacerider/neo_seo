<?php

declare(strict_types=1);

namespace Drupal\neo_seo_analysis\Ajax;

use Drupal\Core\Ajax\CommandInterface;

/**
 * Tells the SEO panel a media item it opened was saved, so it checks again.
 *
 * Handled by analysis.ts (Drupal.AjaxCommands.prototype.neoSeoMediaSaved),
 * which announces it as a `neo-seo:media-saved` event on the document.
 */
class MediaSavedCommand implements CommandInterface {

  /**
   * Constructs the command.
   *
   * @param int $mediaId
   *   The media item saved.
   */
  public function __construct(
    protected int $mediaId,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    return [
      'command' => 'neoSeoMediaSaved',
      'media' => $this->mediaId,
    ];
  }

}
