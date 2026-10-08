<?php

declare(strict_types=1);

namespace Drupal\neo_seo_panel\Hook;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\neo_seo_panel\PanelBuilder;

/**
 * Mounts the SEO panel on the SEO overrides widget.
 *
 * Enabling the module is the switch: the plain widget stays the widget — its
 * inputs are what the form saves — and the panel is mounted inside it, adding
 * previews and feedback around those inputs. No form display changes, so
 * uninstalling the module leaves every form exactly as it was.
 */
class SeoPanelHooks {

  /**
   * Constructs the hooks.
   *
   * @param \Drupal\neo_seo_panel\PanelBuilder $builder
   *   The panel builder.
   */
  public function __construct(
    protected PanelBuilder $builder,
  ) {}

  /**
   * Implements hook_field_widget_single_element_WIDGET_TYPE_form_alter().
   */
  #[Hook('field_widget_single_element_neo_seo_overrides_form_alter')]
  public function widgetAlter(array &$element, FormStateInterface $form_state, array $context): void {
    $entity = $context['items']->getEntity();
    if (!$entity instanceof ContentEntityInterface) {
      return;
    }
    $element['#attributes']['class'][] = 'neo-seo-panel-host';
    $element['panel'] = $this->builder->mount($entity, 'form') + ['#weight' => -100];
  }

}
