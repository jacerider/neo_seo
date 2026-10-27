<?php

declare(strict_types=1);

namespace Drupal\neo_seo_analysis\Hook;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\neo_seo\SeoOverrides;
use Drupal\neo_seo_analysis\AlchemistLayout;
use Drupal\neo_seo_analysis\ImageMedia;
use Drupal\neo_tooltip\Tooltip;

/**
 * Adds content analysis to the SEO panel.
 */
class AnalysisHooks {

  use StringTranslationTrait;

  /**
   * Constructs the hooks.
   *
   * @param \Drupal\neo_seo_analysis\AlchemistLayout $layout
   *   Whether a page is built in its own Alchemist layout.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The current user, who may or may not open the layout editor.
   */
  public function __construct(
    protected AlchemistLayout $layout,
    protected AccountInterface $currentUser,
  ) {}

  /**
   * Implements hook_field_widget_single_element_WIDGET_TYPE_form_alter().
   *
   * The edit form's places for the analysis: under the keyphrase and
   * cornerstone controls in the SEO section, and a Readability section of
   * its own. A page built in its own Alchemist layout gets no Readability
   * section: its checks are made in the editor, and the SEO section shows
   * both scores instead.
   */
  #[Hook('field_widget_single_element_neo_seo_overrides_form_alter')]
  public function widgetAlter(array &$element, FormStateInterface $form_state, array $context): void {
    $entity = $context['items']->getEntity();
    if (!$entity instanceof ContentEntityInterface) {
      return;
    }
    $element['analysis_seo'] = $this->slot('seo');
    if ($this->layout->customField($entity) === NULL) {
      $element['#neo_seo_sections']['readability'] = ['#title' => $this->t('Readability'), '#weight' => 2];
      $element['analysis_readability'] = $this->slot('readability');
    }
  }

  /**
   * An empty place in a section that the analysis script fills.
   */
  protected function slot(string $section): array {
    return [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'class' => ['neo-seo-analysis', 'neo-seo-analysis--' . $section],
        'data-neo-seo-section' => $section,
      ],
      '#neo_seo_section' => $section,
      '#weight' => 100,
    ];
  }

  /**
   * Implements hook_form_alter().
   *
   * The Alchemist Save confirmation (the entity form operation
   * `alchemist_publish`, whatever the entity type) gets an empty, labelled
   * place for an SEO summary, which the badge's script fills from the
   * analysis it already holds when the confirmation opens. Advice only: no
   * field, no validation, nothing that touches the save. Only for an editor
   * who can see the SEO panel; without an analysis it stays hidden.
   */
  #[Hook('form_alter')]
  public function formAlter(array &$form, FormStateInterface $form_state, string $form_id): void {
    $formObject = $form_state->getFormObject();
    if (!$formObject instanceof EntityFormInterface || $formObject->getOperation() !== 'alchemist_publish') {
      return;
    }
    $entity = $formObject->getEntity();
    if (!$entity instanceof ContentEntityInterface || !$entity->hasField(SeoOverrides::FIELD)) {
      return;
    }
    $access = $entity->get(SeoOverrides::FIELD)->access('edit', NULL, TRUE);
    CacheableMetadata::createFromRenderArray($form)->addCacheableDependency($access)->applyTo($form);
    if (!$access->isAllowed()) {
      return;
    }
    $form['neo_seo_summary'] = [
      '#type' => 'html_tag',
      '#tag' => 'section',
      '#attributes' => [
        'class' => ['neo-seo-save-summary'],
        'data-neo-seo-save-summary' => '',
        'aria-label' => (string) $this->t('SEO summary'),
        'hidden' => 'hidden',
      ],
      '#weight' => 90,
      '#attached' => ['library' => ['neo_seo_analysis/panel']],
    ];
  }

  /**
   * Implements hook_neo_seo_panel_badge_alter().
   *
   * The Alchemist band shows the page's SEO and readability scores.
   */
  #[Hook('neo_seo_panel_badge_alter')]
  public function neoSeoPanelBadgeAlter(array &$badge, ContentEntityInterface $entity): void {
    // The two scores as meters on the editor's dark band: a short name, a bar
    // filled to the score in its band's colour, the number. analysis.ts fills
    // them in and marks the open one active. The bar is what the band drops
    // first when it runs short of room (neo-alchemist--compact-hide); the
    // number stays.
    $badge['neo_seo_analysis'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => ['class' => ['neo-seo-pills']],
    ];
    $names = ['seo' => $this->t('SEO score'), 'readability' => $this->t('Readability score')];
    foreach (['seo' => $this->t('SEO'), 'readability' => $this->t('Read')] as $kind => $label) {
      $badge['neo_seo_analysis'][$kind] = [
        '#type' => 'html_tag',
        '#tag' => 'button',
        '#attributes' => [
          'type' => 'button',
          'class' => ['neo-seo-pill', 'neo-seo-pill--meter', 'is-updating'],
          'data-neo-seo-score' => $kind,
          'data-label' => $names[$kind],
          'data-band' => 'none',
          // On the body, beside the band's other tooltips.
          'data-neo-tooltip-detach' => 'true',
        ],
        'label' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => $label,
          '#attributes' => ['class' => ['neo-seo-pill__label']],
        ],
        // The score as a bar; analysis.ts sets how full.
        'meter' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#attributes' => [
            'class' => ['neo-seo-pill__meter', 'neo-alchemist--compact-hide'],
            'aria-hidden' => 'true',
          ],
          'fill' => [
            '#type' => 'html_tag',
            '#tag' => 'span',
            '#attributes' => ['class' => ['neo-seo-pill__fill']],
          ],
        ],
        'value' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => '…',
          '#attributes' => ['class' => ['neo-seo-pill__value']],
        ],
      ];
      // Named until analysis.ts has the score to show instead.
      (new Tooltip($names[$kind], ['placement' => 'bottom']))->applyTo($badge['neo_seo_analysis'][$kind]);
    }
    $badge['#attached']['library'][] = 'neo_seo_analysis/panel';
  }

  /**
   * Implements hook_neo_seo_panel_alter().
   *
   * Both hosts get the analysis library and the endpoint that lists the
   * keyphrases other pages already use.
   */
  #[Hook('neo_seo_panel_alter')]
  public function neoSeoPanelAlter(array &$panel, ContentEntityInterface $entity, string $host): void {
    $query = ['entity_type' => $entity->getEntityTypeId()];
    if (!$entity->isNew()) {
      $query['exclude'] = $entity->id();
    }
    $panel['libraries'][] = 'neo_seo_analysis/panel';
    $panel['config']['analysis'] = [
      'keyphrasesUrl' => Url::fromRoute('neo_seo_analysis.keyphrases', [], ['query' => $query])
        ->toString(TRUE)->getGeneratedUrl(),
      // Finds the media item behind an image the alt text check flags.
      'imagesUrl' => ImageMedia::url(),
    ];
    // A page built in its own layout: the form shows the scores, and the
    // way to the editor where the checks can be acted on.
    $field = $host === 'form' ? $this->layout->customField($entity) : NULL;
    if ($field !== NULL) {
      $editor = $this->layout->editorUrl($entity, $field, $this->currentUser);
      $panel['config']['analysis']['summary'] = [
        'editorUrl' => $editor?->toString(TRUE)->getGeneratedUrl(),
      ];
    }
  }

}
