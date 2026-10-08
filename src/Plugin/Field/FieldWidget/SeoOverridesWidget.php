<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Plugin\Field\FieldWidget;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\Attribute\FieldWidget;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_seo\Mapping\BundleMappingRepository;
use Drupal\neo_seo\Schema\SchemaTypes;
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
    $element['image'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Share image'),
      '#target_type' => 'media',
      '#default_value' => $item->image ? $this->entityTypeManager->getStorage('media')->load($item->image) : NULL,
      '#description' => $this->t('Shown when the page is shared. 1200×630 works everywhere.'),
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
      '#description' => $this->t('One of the site’s most important pages. Analysis holds it to a higher standard.'),
      '#default_value' => (bool) $item->cornerstone,
    ];
    // The scores are written by analysis, not typed; carry them through so
    // saving the form does not wipe them.
    foreach (['seo_score', 'readability_score'] as $score) {
      $element[$score] = ['#type' => 'value', '#value' => $item->{$score}];
    }
    return $element;
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
    }
    return $values;
  }

}
