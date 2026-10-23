<?php

declare(strict_types=1);

namespace Drupal\neo_seo_panel\Form;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Field\WidgetPluginManager;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_seo\PageUrl;
use Drupal\neo_seo\PageUrlField;
use Drupal\neo_seo\SeoOverrides;
use Drupal\neo_seo_panel\PanelBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The SEO overrides controls inside the Alchemist editor's SEO panel.
 *
 * The same elements the edit form's widget builds — built by that widget — so
 * both hosts show the same controls with the same validation hints. It is
 * never submitted: the panel reads its controls and writes the shared draft.
 */
class SeoPanelForm extends FormBase {

  /**
   * The field widget plugin manager.
   */
  protected WidgetPluginManager $widgetManager;

  /**
   * The panel builder.
   */
  protected PanelBuilder $builder;

  /**
   * The page URL service, to show a drafted URL.
   */
  protected PageUrl $pageUrl;

  /**
   * The page URL field, for editors who may change the page's URL.
   */
  protected PageUrlField $pageUrlField;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = new static();
    $instance->widgetManager = $container->get('plugin.manager.field.widget');
    $instance->builder = $container->get('neo_seo_panel.builder');
    $instance->pageUrl = $container->get('neo_seo.page_url');
    $instance->pageUrlField = $container->get('neo_seo.page_url_field');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'neo_seo_panel_form';
  }

  /**
   * {@inheritdoc}
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param \Drupal\Core\Entity\ContentEntityInterface|null $entity
   *   The entity being edited.
   * @param array|null $draft
   *   The overrides drafted in the panel so far, if any, with the URL
   *   controls' values (PageUrl::KEYS).
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?ContentEntityInterface $entity = NULL, ?array $draft = NULL) {
    if (!$entity || !$entity->hasField(SeoOverrides::FIELD)) {
      return $form;
    }
    // The controls show the draft; the entity the editor holds is not touched.
    $entity = clone $entity;
    if ($draft) {
      $url = array_intersect_key($draft, array_flip(PageUrl::KEYS));
      $entity->set(SeoOverrides::FIELD, array_diff_key($draft, $url) + SeoOverrides::of($entity));
      $this->pageUrl->apply($entity, $this->currentUser(), $url);
    }
    $items = $entity->get(SeoOverrides::FIELD);
    // The widget is built for item 0: a page with no overrides yet has none.
    if ($items->isEmpty()) {
      $items->appendItem();
    }
    /** @var \Drupal\Core\Field\WidgetInterface $widget */
    $widget = $this->widgetManager->getInstance([
      'field_definition' => $items->getFieldDefinition(),
      'form_mode' => 'default',
      'prepare' => TRUE,
      'configuration' => ['type' => 'neo_seo_overrides', 'settings' => [], 'third_party_settings' => []],
    ]);
    $form['#parents'] = [];
    $base = [
      '#field_parents' => [],
      '#delta' => 0,
      '#weight' => 0,
      '#title' => $items->getFieldDefinition()->getLabel(),
      '#description' => '',
      '#required' => FALSE,
    ];
    $element = $widget->formElement($items, 0, $base, $form, $form_state);
    // One flat list: the side panel is a section of its own, and the
    // keyphrase and cornerstone controls move to the top bar's SEO dropdown.
    $element['#neo_seo_sections'] = [];
    unset($element['#title']);
    $element['panel'] = $this->builder->mount($entity, 'alchemist') + ['#weight' => -100];
    // The URL under the previews, above the title: in the order a search
    // result shows them, address, title, description. Only for editors who
    // may change the page's URL.
    if ($this->pageUrlField->access($entity, $this->currentUser())) {
      $element['url_field'] = $this->pageUrlField->build($entity, $this->currentUser(), NULL, TRUE) + ['#weight' => -50];
      $element['url_field']['heading'] = [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#value' => new TranslatableMarkup('URL'),
        '#attributes' => ['class' => ['neo-seo-url-field__heading']],
        '#weight' => -10,
      ];
    }

    $form[SeoOverrides::FIELD] = ['#tree' => TRUE, 0 => $element];
    $form['#attributes']['class'][] = 'neo-seo-panel-form';
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Never submitted: the panel writes the shared draft instead.
  }

}
