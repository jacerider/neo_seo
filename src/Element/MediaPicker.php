<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Element;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Attribute\FormElement;
use Drupal\Core\Render\Element;
use Drupal\Core\Render\Element\FormElementBase;
use Drupal\Core\Url;
use Drupal\image\Entity\ImageStyle;
use Drupal\media\MediaInterface;
use Drupal\media\Plugin\media\Source\Image;
use Drupal\media_library\MediaLibraryState;
use Drupal\media_library\MediaLibraryUiBuilder;

/**
 * One image media item, chosen in the media library.
 *
 * The value is the media item's id. It is a hidden input carrying the
 * element's own name, beside a preview and the buttons that change it: the
 * media library opens in a dialog from a plain link, so the picker works in any
 * form, an SEO panel moved into the Alchemist editor included, with no form
 * rebuild. A choice comes back through the neo_seo.media_library_opener
 * opener as a MediaPickedCommand.
 *
 * Only usable while media_library is enabled; neo_seo falls back to an
 * autocomplete without it (see ::available()).
 *
 * Usage:
 * @code
 * $form['image'] = [
 *   '#type' => 'neo_seo_media',
 *   '#title' => $this->t('Share image'),
 *   '#default_value' => $mediaId,
 * ];
 * @endcode
 */
#[FormElement('neo_seo_media')]
class MediaPicker extends FormElementBase {

  /**
   * The opener service that answers the media library.
   */
  public const OPENER = 'neo_seo.media_library_opener';

  /**
   * {@inheritdoc}
   */
  public function getInfo() {
    return [
      '#input' => TRUE,
      '#process' => [[static::class, 'processPicker']],
      '#pre_render' => [[static::class, 'preRenderPicker']],
      '#theme' => 'input__hidden',
      '#theme_wrappers' => ['form_element'],
    ];
  }

  /**
   * Whether the picker can be used: the media library is there to open.
   */
  public static function available(): bool {
    return \Drupal::moduleHandler()->moduleExists('media_library');
  }

  /**
   * {@inheritdoc}
   */
  public static function valueCallback(&$element, $input, FormStateInterface $form_state) {
    if ($input === FALSE) {
      return (string) ($element['#default_value'] ?? '');
    }
    return is_scalar($input) && preg_match('/^\d+$/', (string) $input) ? (string) $input : '';
  }

  /**
   * Adds the preview and the buttons that open the library and clear it.
   */
  public static function processPicker(array &$element, FormStateInterface $form_state, array &$complete_form): array {
    $key = $element['#id'];
    $element['#attributes']['data-neo-seo-media'] = $key;
    $media = $element['#value'] !== '' ? \Drupal::entityTypeManager()->getStorage('media')->load($element['#value']) : NULL;
    $types = static::imageTypes();
    $state = MediaLibraryState::create(static::OPENER, $types ?: ['image'], reset($types) ?: 'image', 1, ['neo_seo_media' => $key]);
    $element['#field_suffix'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['neo-seo-media'], 'data-neo-seo-media-picker' => $key],
      'preview' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['neo-seo-media__preview'], 'data-neo-seo-media-preview' => $key],
        'media' => static::preview($media instanceof MediaInterface ? $media : NULL),
      ],
      'actions' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['neo-seo-media__actions']],
        'open' => [
          '#type' => 'link',
          '#title' => t('Choose image'),
          '#url' => Url::fromRoute('media_library.ui', [], ['query' => $state->all()]),
          '#attributes' => [
            'class' => ['use-ajax', 'btn', 'btn-xs', 'btn-outline', 'neo-seo-media__open'],
            'data-dialog-type' => 'modal',
            'data-dialog-options' => json_encode(MediaLibraryUiBuilder::dialogOptions()),
            'role' => 'button',
          ],
        ],
        'remove' => [
          '#type' => 'html_tag',
          '#tag' => 'button',
          '#value' => t('Remove'),
          '#attributes' => [
            'type' => 'button',
            'class' => ['btn', 'btn-xs', 'btn-outline', 'neo-seo-media__remove'],
            'data-neo-seo-media-remove' => $key,
            'hidden' => $media ? NULL : 'hidden',
          ],
        ],
      ],
    ];
    $element['#attached']['library'][] = 'core/drupal.dialog.ajax';
    $element['#attached']['library'][] = 'neo_seo/media_picker';
    return $element;
  }

  /**
   * Renders the picker's own value as the hidden input it is.
   */
  public static function preRenderPicker(array $element): array {
    $element['#attributes']['type'] = 'hidden';
    Element::setAttributes($element, ['id', 'name', 'value']);
    return $element;
  }

  /**
   * A media item as the picker shows it: its thumbnail and its name.
   *
   * @param \Drupal\media\MediaInterface|null $media
   *   The media item, or NULL for none.
   *
   * @return array
   *   A render array.
   */
  public static function preview(?MediaInterface $media): array {
    if (!$media) {
      return [
        '#markup' => '<span class="neo-seo-media__empty">' . t('No image chosen.') . '</span>',
      ];
    }
    $uri = $media->get('thumbnail')->entity?->getFileUri();
    $style = ImageStyle::load('media_library') ?? ImageStyle::load('thumbnail');
    $src = $uri ? ($style ? $style->buildUrl($uri) : \Drupal::service('file_url_generator')->generateString($uri)) : NULL;
    return [
      '#type' => 'inline_template',
      '#template' => '{% if src %}<img class="neo-seo-media__thumb" src="{{ src }}" alt="" width="64" height="64">{% endif %}<span class="neo-seo-media__name">{{ name }}</span>',
      '#context' => ['src' => $src, 'name' => $media->label()],
      '#cache' => ['tags' => $media->getCacheTags()],
    ];
  }

  /**
   * The media types whose source is an image file.
   *
   * @return string[]
   *   Media type ids.
   */
  public static function imageTypes(): array {
    $types = [];
    foreach (\Drupal::entityTypeManager()->getStorage('media_type')->loadMultiple() as $id => $type) {
      if ($type->getSource() instanceof Image) {
        $types[] = (string) $id;
      }
    }
    return $types;
  }

}
