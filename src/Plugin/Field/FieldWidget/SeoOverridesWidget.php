<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Plugin\Field\FieldWidget;

use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\Attribute\FieldWidget;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_seo\Element\MediaPicker;
use Drupal\neo_seo\Mapping\BundleMappingRepository;
use Drupal\neo_seo\Plugin\Field\FieldType\SeoOverridesItem;
use Drupal\neo_seo\Schema\SchemaTypes;
use Drupal\neo_seo\SeoOverrides;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The SEO overrides as a sidebar section of the edit form.
 *
 * The plain form. The SEO panel (neo_seo_panel) replaces it with previews and
 * analysis, and still writes these same values.
 */
#[FieldWidget(
  id: 'neo_seo_overrides',
  label: new TranslatableMarkup('SEO'),
  field_types: ['neo_seo_overrides'],
)]
class SeoOverridesWidget extends WidgetBase {

  /**
   * The entity type manager, to load the share image.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The bundle mapping repository, for the types an editor may choose.
   */
  protected BundleMappingRepository $mapping;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->mapping = $container->get('neo_seo.bundle_mapping');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    /** @var \Drupal\neo_seo\Plugin\Field\FieldType\SeoOverridesItem $item */
    $item = $items[$delta];
    $entity = $items->getEntity();
    $mapping = $this->mapping->forBundle($entity->getEntityTypeId(), $entity->bundle());
    $element += [
      '#type' => 'details',
      '#open' => !$items->isEmpty(),
      '#group' => isset($form['advanced']) ? 'advanced' : NULL,
      '#weight' => 90,
      '#attributes' => ['class' => ['neo-seo-overrides']],
    ];
    $element['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Title'),
      '#default_value' => $item->title,
      '#maxlength' => 255,
      '#description' => $this->t('Replaces the title template for this page. Search results show about 60 characters.'),
    ];
    $element['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#default_value' => $item->description,
      '#rows' => 3,
      '#description' => $this->t('Replaces the description template. Search results show about 155 characters.'),
    ];
    // The media library where it is enabled; an autocomplete otherwise.
    $element['image'] = MediaPicker::available()
      ? [
        '#type' => 'neo_seo_media',
        '#default_value' => $item->image ? (string) $item->image : '',
      ]
      : [
        '#type' => 'entity_autocomplete',
        '#target_type' => 'media',
        '#default_value' => $item->image ? $this->entityTypeManager->getStorage('media')->load($item->image) : NULL,
      ];
    $element['image'] += [
      '#title' => $this->t('Share image'),
      '#description' => $this->t('Shown when the page is shared. 1200×630 works everywhere. Leave it empty to share the page’s own image.'),
    ];
    $element['keyphrase'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Focus keyphrase'),
      '#default_value' => $item->keyphrase,
      '#maxlength' => 255,
    ];
    $element['page_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Page type'),
      '#options' => array_intersect_key(SchemaTypes::pageTypes(), array_flip($mapping->allowedPageTypes())),
      '#empty_option' => $this->t('- Default (@type) -', ['@type' => SchemaTypes::pageTypes()[$mapping->pageType]]),
      '#default_value' => $item->page_type,
      '#access' => count($mapping->allowedPageTypes()) > 1,
    ];
    $element['article_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Article type'),
      '#options' => array_intersect_key(SchemaTypes::articleTypes(), array_flip($mapping->allowedArticleTypes())),
      '#empty_option' => $this->t('- Default (@type) -', ['@type' => SchemaTypes::articleTypes()[$mapping->articleType]]),
      '#default_value' => $item->article_type,
      '#access' => count($mapping->allowedArticleTypes()) > 1,
    ];
    $element['noindex'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Hide from search results'),
      '#default_value' => (bool) $item->noindex,
    ];
    $element['cornerstone'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Cornerstone content'),
      '#default_value' => (bool) $item->cornerstone,
    ];

    // Plain-language help, the same wherever these controls are shown.
    $this->help($element['keyphrase'],
      $this->t('The search phrase you most want this page found for.'),
      $this->t('The words someone would type into Google to find this page — e.g. “healing gardens”. Pick one phrase per page and don’t reuse it on another page, or your pages compete with each other. The checks show where the phrase is missing: title, description, first paragraph, headings, image alt text. It is never shown to visitors; it only guides these suggestions.'),
    );
    $this->help($element['cornerstone'],
      $this->t('One of the site’s most important pages.'),
      $this->t('Mark the few pages you most want to rank — usually long, authoritative pages others link to. They are held to stricter checks (more text, tighter readability).'),
    );
    foreach (['page_type', 'article_type'] as $type) {
      $this->help($element[$type],
        $this->t('What kind of page this is, for search engines.'),
        $this->t('Search engines use this to understand the page — an About page, a profile, a blog post. It doesn’t change how the page looks. Leave the default unless the page really is a different kind.'),
      );
    }
    $this->help($element['noindex'],
      $this->t('Ask search engines not to list this page.'),
      $this->t('For pages that shouldn’t appear in Google — thank-you pages, campaign landing pages. The page stays on the site and works normally for anyone with the link.'),
    );
    // The scores are written by analysis (neo_seo_analysis), not typed. Hidden
    // inputs, so the panel can set them before a save and a save without the
    // panel carries the stored ones through.
    foreach (SeoOverridesItem::SCORES as $score) {
      $element[$score] = [
        '#type' => 'hidden',
        '#default_value' => $item->{$score} === NULL ? '' : (string) $item->{$score},
      ];
    }
    return $element;
  }

  /**
   * Gives a control its help: a one-line hint and the longer explanation.
   *
   * As the control's #description, like the fields beside it, so it is shown
   * the way the site shows every description: behind a "?" badge where
   * neo_tooltip makes descriptions tooltips, under the field where it does
   * not. Either way the control is described by it.
   *
   * @param array $element
   *   The control.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $hint
   *   The one-line hint.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $more
   *   The longer explanation.
   */
  protected function help(array &$element, TranslatableMarkup $hint, TranslatableMarkup $more): void {
    $element['#description'] = Markup::create(
      '<strong>' . Html::escape((string) $hint) . '</strong> ' . Html::escape((string) $more)
    );
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    foreach ($values as &$value) {
      $value['image'] = !empty($value['image']) ? (int) $value['image'] : NULL;
      foreach (['page_type', 'article_type', 'title', 'description', 'keyphrase'] as $key) {
        $value[$key] = ($value[$key] ?? '') === '' ? NULL : $value[$key];
      }
      $value['noindex'] = (bool) ($value['noindex'] ?? FALSE);
      $value['cornerstone'] = (bool) ($value['cornerstone'] ?? FALSE);
      // Scores are advisory: one that is not a 0–100 integer is dropped, never
      // reported as an error that would stop the save.
      foreach (SeoOverridesItem::SCORES as $score) {
        $clean = SeoOverrides::score($value[$score] ?? NULL);
        $value[$score] = $clean === FALSE ? NULL : $clean;
      }
    }
    return $values;
  }

}
