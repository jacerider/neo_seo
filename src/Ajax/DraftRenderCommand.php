<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Ajax;

use Drupal\Core\Ajax\CommandInterface;

/**
 * Hands a draft render to the SEO panel in the browser.
 */
class DraftRenderCommand implements CommandInterface {

  /**
   * Constructs the command.
   *
   * @param array $render
   *   The draft render.
   */
  public function __construct(
    protected array $render,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function render() {
    return ['command' => 'neoSeoDraftRender', 'render' => $this->render];
  }

}
