<?php

declare(strict_types=1);

namespace Drupal\neo_seo_panel\Form;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Field\WidgetPluginManager;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
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
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = new static();
    $instance->widgetManager = $container->get('plugin.manager.field.widget');
    $instance->builder = $container->get('neo_seo_panel.builder');
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
   *   The overrides drafted in the panel so far, if any.
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?ContentEntityInterface $entity = NULL, ?array $draft = NULL) {
    if (!$entity || !$entity->hasField(SeoOverrides::FIELD)) {
      return $form;
    }
    // The controls show the draft; the entity the editor holds is not touched.
    $entity = clone $entity;
    if ($draft) {
      $entity->set(SeoOverrides::FIELD, $draft + SeoOverrides::of($entity));
    }
    $items = $entity->get(SeoOverrides::FIELD);
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
    $element['#type'] = 'container';
    unset($element['#group'], $element['#open'], $element['#title']);
    $element['panel'] = $this->builder->mount($entity, 'alchemist') + ['#weight' => -100];

    $form[SeoOverrides::FIELD] = ['#tree' => TRUE, 0 => $element];
    $form['#attributes']['class'][] = 'neo-seo-panel-form';
    $form['#attributes']['class'][] = 'p-3';
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Never submitted: the panel writes the shared draft instead.
  }

}
