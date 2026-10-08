<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Plugin\views\display_extender;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_seo\Schema\SchemaTypes;
use Drupal\views\Attribute\ViewsDisplayExtender;
use Drupal\views\Plugin\views\display_extender\DisplayExtenderPluginBase;

/**
 * SEO for a Views page: templates and a page type, stored in the view.
 */
#[ViewsDisplayExtender(
  id: 'neo_seo',
  title: new TranslatableMarkup('SEO'),
  help: new TranslatableMarkup('Search title, description, share image and page type for Views pages.'),
)]
class SeoDisplayExtender extends DisplayExtenderPluginBase {

  /**
   * The settings, with their defaults: empty means "use the global templates".
   */
  public const DEFAULTS = [
    'title' => '',
    'description' => '',
    'image' => '',
    'page_type' => '',
  ];

  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();
    $options['seo'] = ['default' => static::DEFAULTS];
    return $options;
  }

  /**
   * Whether this display is a page, the only kind SEO applies to.
   */
  protected function isPage(): bool {
    return $this->displayHandler->getPluginId() === 'page';
  }

  /**
   * {@inheritdoc}
   */
  public function optionsSummary(&$categories, &$options) {
    if (!$this->isPage()) {
      return;
    }
    $seo = (array) $this->options['seo'] + static::DEFAULTS;
    $summary = array_filter([
      $seo['page_type'] ? (string) (SchemaTypes::pageTypes()[$seo['page_type']] ?? $seo['page_type']) : '',
      $seo['title'] ? Unicode::truncate($seo['title'], 24, FALSE, TRUE) : '',
    ]);
    $options['neo_seo'] = [
      'category' => 'page',
      'title' => $this->t('SEO'),
      'value' => $summary ? implode(', ', $summary) : $this->t('Global defaults'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    if ($form_state->get('section') !== 'neo_seo') {
      return;
    }
    $seo = (array) $this->options['seo'] + static::DEFAULTS;
    $form['#title'] .= $this->t('SEO');
    $form['seo'] = [
      '#type' => 'container',
      '#tree' => TRUE,
    ];
    $form['seo']['page_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Page type'),
      '#options' => SchemaTypes::pageTypes(),
      '#empty_option' => $this->t('- Web page -'),
      '#default_value' => $seo['page_type'],
      '#description' => $this->t('A listing is usually a Collection page; a search page a Search results page.'),
    ];
    $fields = [
      'title' => $this->t('Title'),
      'description' => $this->t('Description'),
      'image' => $this->t('Share image'),
    ];
    foreach ($fields as $key => $label) {
      $form['seo'][$key] = [
        '#type' => 'textfield',
        '#title' => $label,
        '#default_value' => $seo[$key],
        '#maxlength' => 1024,
        '#description' => $this->t('Leave empty for the global template. Tokens allowed, including [view:title] and [view:url].'),
      ];
    }
    $form['seo']['tokens'] = [
      '#theme' => 'token_tree_link',
      '#token_types' => ['view', 'neo'],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function submitOptionsForm(&$form, FormStateInterface $form_state) {
    if ($form_state->get('section') !== 'neo_seo') {
      return;
    }
    $values = (array) $form_state->getValue('seo');
    $this->options['seo'] = array_map(
      static fn($value): string => trim((string) $value),
      array_intersect_key($values, static::DEFAULTS),
    ) + static::DEFAULTS;
  }

}
