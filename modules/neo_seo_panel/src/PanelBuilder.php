<?php

declare(strict_types=1);

namespace Drupal\neo_seo_panel;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ThemeSettingsProvider;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Url;

/**
 * Builds the element the SEO panel mounts on, for either host.
 *
 * The panel itself is browser code (src/js). What the server hands it is one
 * element carrying its config as JSON, and the libraries it needs. The two
 * hosts differ only in the `host` key; everything else the browser learns from
 * the draft render.
 */
class PanelBuilder {

  /**
   * Constructs the builder.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Extension\ThemeSettingsProvider $themeSettings
   *   The theme settings provider.
   * @param \Drupal\Core\File\FileUrlGeneratorInterface $fileUrlGenerator
   *   The file URL generator.
   */
  public function __construct(
    protected ModuleHandlerInterface $moduleHandler,
    protected ConfigFactoryInterface $configFactory,
    protected ThemeSettingsProvider $themeSettings,
    protected FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  /**
   * The panel's mount element.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity being edited.
   * @param string $host
   *   The host: 'form' or 'alchemist'.
   *
   * @return array
   *   A render array.
   */
  public function mount(ContentEntityInterface $entity, string $host): array {
    $panel = [
      'config' => [
        'host' => $host,
        'entityType' => $entity->getEntityTypeId(),
        'entityId' => $entity->isNew() ? NULL : (string) $entity->id(),
        // The edit form renders through its own AJAX button; Alchemist, whose
        // drafts live on the server, through the draft render endpoint.
        'renderUrl' => $entity->isNew() ? NULL : Url::fromRoute('neo_seo.draft_render', [
          'entity_type_id' => $entity->getEntityTypeId(),
          'entity' => $entity->id(),
        ])->toString(TRUE)->getGeneratedUrl(),
        'favicon' => $this->favicon(),
      ],
      'libraries' => ['neo_seo_panel/panel'],
    ];
    $this->moduleHandler->alter('neo_seo_panel', $panel, $entity, $host);
    return [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'class' => ['neo-seo-panel'],
        'data-neo-seo-panel' => Json::encode($panel['config']),
      ],
      '#attached' => ['library' => array_values(array_unique($panel['libraries']))],
    ];
  }

  /**
   * The site's favicon as the front-end theme prints it, for the previews.
   *
   * Neo's generated favicon when there is one (neo_favicon), else the front
   * theme's favicon setting.
   */
  protected function favicon(): ?string {
    foreach (['favicon-96x96.png', 'favicon.svg', 'favicon.ico'] as $file) {
      if (file_exists('public://neo-favicon/' . $file)) {
        return $this->fileUrlGenerator->generateString('public://neo-favicon/' . $file);
      }
    }
    $theme = (string) $this->configFactory->get('system.theme')->get('default');
    $url = $theme !== '' ? $this->themeSettings->getSetting('favicon.url', $theme) : NULL;
    return is_string($url) && $url !== '' ? $url : NULL;
  }

}
