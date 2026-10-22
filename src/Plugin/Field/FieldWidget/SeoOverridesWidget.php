<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Plugin\Field\FieldWidget;

use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\Attribute\FieldWidget;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Render\ElementInfoManagerInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_seo\Element\MediaPicker;
use Drupal\neo_seo\Mapping\BundleMappingRepository;
use Drupal\neo_seo\PageUrl;
use Drupal\neo_seo\Plugin\Field\FieldType\SeoOverridesItem;
use Drupal\neo_seo\Schema\SchemaTypes;
use Drupal\neo_seo\SeoOverrides;
use Drupal\neo_seo\Snippet;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The SEO overrides as sidebar sections of the edit form.
 *
 * Two sections, each a details element of its own: "Search & social" (how the
 * page is listed and shared) and "SEO" (what it is analysed for). Other
 * modules add theirs to #neo_seo_sections and place an element in one with
 * #neo_seo_section; anything placed in none is in the first. The controls are
 * moved into their sections as the form is built, keeping their names, so
 * values are submitted as one flat set wherever they are shown.
 *
 * The plain form. The SEO panel (neo_seo_panel) adds previews and analysis
 * to it, and still writes these same values.
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
   * The element info manager, for a container's own process callbacks.
   */
  protected ElementInfoManagerInterface $elementInfo;

  /**
   * The snippet input: the title and description with tokens as pills.
   */
  protected Snippet $snippet;

  /**
   * The page URL service, for the slug and custom URL.
   */
  protected PageUrl $pageUrl;

  /**
   * The current user, for what of the URL they may change.
   */
  protected AccountInterface $currentUser;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->mapping = $container->get('neo_seo.bundle_mapping');
    $instance->elementInfo = $container->get('element_info');
    $instance->snippet = $container->get('neo_seo.snippet');
    $instance->pageUrl = $container->get('neo_seo.page_url');
    $instance->currentUser = $container->get('current_user');
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
      '#type' => 'container',
      '#attributes' => ['class' => ['neo-seo-overrides']],
      '#neo_seo_group' => isset($form['advanced']) ? 'advanced' : NULL,
      '#neo_seo_sections' => [
        'search_social' => [
          '#title' => $this->t('Search & social'),
          '#description' => $element['#description'] ?? '',
          '#weight' => 0,
        ],
        'seo' => ['#title' => $this->t('SEO'), '#weight' => 1],
      ],
      '#process' => array_merge(
        $this->elementInfo->getInfoProperty('container', '#process', []),
        [[static::class, 'processSections']],
      ),
    ];
    $this->urlControls($element, $item, array_merge($element['#field_parents'] ?? [], [$items->getName(), $delta]));
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
    // Tokens as named pills; an empty field shows the template it overrides.
    $templates = $this->snippet->templates($entity);
    $types = [$this->snippet->tokenType($entity->getEntityTypeId())];
    $this->snippet->attach($element['title'], $types, $templates['title']);
    $this->snippet->attach($element['description'], $types, $templates['description']);
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
      '#neo_seo_section' => 'seo',
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
      '#neo_size' => 'xs',
    ];
    $element['cornerstone'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Cornerstone content'),
      '#default_value' => (bool) $item->cornerstone,
      '#neo_size' => 'xs',
      '#neo_seo_section' => 'seo',
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
   * The page's URL: the editor's slug, or a URL of the page's own.
   *
   * Only where neo_seo manages the page's URL (ADR 0005), which hides core's
   * path widget (PageUrlHooks). The slug shows only where the pattern holds
   * [neo:slug]; the custom URL to those who may set one, and read-only to
   * anyone else on a page that already has one, so they can hand it back to
   * the pattern. Under them, the URL the page will have once saved, which the
   * SEO panel keeps current from its renders.
   *
   * @param array $element
   *   The widget element.
   * @param \Drupal\neo_seo\Plugin\Field\FieldType\SeoOverridesItem $item
   *   The overrides item.
   * @param array $parents
   *   The element's #parents, for the controls' #states.
   */
  protected function urlControls(array &$element, SeoOverridesItem $item, array $parents): void {
    $entity = $item->getEntity();
    if (!$entity instanceof ContentEntityInterface || !$this->pageUrl->manages($entity)) {
      return;
    }
    $url = $this->pageUrl->describe($entity);
    $mayCustomize = $this->pageUrl->mayCustomize($entity, $this->currentUser);
    $hasPattern = $this->pageUrl->pattern($entity) !== NULL;
    $name = array_shift($parents) . ($parents ? '[' . implode('][', $parents) . ']' : '') . '[url_custom]';
    $customOn = [':input[name="' . $name . '"]' => ['checked' => TRUE]];

    // One URL field: the slug and the custom URL share its place, one shown at
    // a time, with the address they make under them and the switch between
    // them last, so switching moves nothing the editor is looking at.
    $element['slug'] = [
      '#type' => 'textfield',
      '#title' => $this->t('URL'),
      '#default_value' => $item->slug,
      '#maxlength' => 255,
      '#placeholder' => !$url['custom'] && $url['prefix'] !== '' ? $url['slug'] : '',
      '#access' => $this->pageUrl->usesSlug($entity),
      '#states' => ['invisible' => $customOn],
    ];
    $this->help($element['slug'],
      $this->t('The page’s own part of its address.'),
      $this->t('Leave it empty to use the title. Lowercase words joined by hyphens read best, e.g. “water-treatment-upgrade”. The rest of the address is set by the site, often from where the page sits in the menu.'),
    );
    $element['url_alias'] = [
      '#type' => 'textfield',
      '#title' => $this->t('URL'),
      '#default_value' => $url['alias'] ?? '',
      '#maxlength' => 255,
      '#access' => $mayCustomize || $url['custom'],
      '#disabled' => !$mayCustomize,
      '#states' => $hasPattern ? ['visible' => $customOn] : [],
      '#description' => $mayCustomize
        ? $this->t('The whole address after the domain, starting with a slash, e.g. /about-us.')
        : $this->t('This page has an address of its own. Untick “Use a custom URL” to give it the automatic one.'),
    ];
    $element['url_preview'] = static::urlPreview($url);
    $element['url_custom'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use a custom URL'),
      '#default_value' => $url['custom'],
      '#access' => $hasPattern && ($mayCustomize || $url['custom']),
      '#neo_size' => 'xs',
    ];
  }

  /**
   * The URL a page will have once saved, with what an editor should know.
   *
   * The SEO panel redraws it from each draft render (neo_seo_panel).
   *
   * @param array $url
   *   The page's URL, as PageUrl::describe() gives it.
   */
  public static function urlPreview(array $url): array {
    $notes = [];
    if ($url['error']) {
      $notes['error'] = ['#markup' => $url['error'], '#wrapper_attributes' => ['class' => ['is-error']]];
    }
    elseif ($url['alias'] === NULL) {
      $notes['new'] = ['#markup' => new TranslatableMarkup('The address is set when the page is first saved.')];
    }
    if ($url['notice']) {
      $notes['notice'] = ['#markup' => $url['notice']];
    }
    if ($url['redirect']) {
      $notes['redirect'] = ['#markup' => new TranslatableMarkup('The old address will redirect here.')];
    }
    if ($url['children']) {
      $notes['children'] = ['#markup' => \Drupal::translation()->formatPlural($url['children'], 'The page under this one moves with it.', 'The @count pages under this one move with it.')];
    }
    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['neo-seo-url'],
        'data-neo-seo-url' => '',
        'aria-live' => 'polite',
      ],
      '#attached' => ['library' => ['neo_seo/page_url']],
      'address' => [
        '#type' => 'inline_template',
        '#template' => '<p class="neo-seo-url__address"><span class="neo-seo-url__prefix">{{ prefix }}</span><strong class="neo-seo-url__slug">{{ slug }}</strong></p>',
        '#context' => ['prefix' => $url['prefix'], 'slug' => $url['slug']],
        '#access' => $url['alias'] !== NULL,
      ],
      'notes' => [
        '#theme' => 'item_list',
        '#items' => $notes,
        '#attributes' => ['class' => ['neo-seo-url__notes']],
        '#access' => (bool) $notes,
      ],
    ];
  }

  /**
   * Moves the controls into their sections, as details elements.
   *
   * Each control keeps the name it has outside a section: its #parents are
   * set before it moves, so the sections add nothing to them. The sections
   * start closed. With no sections (the Alchemist panel's form) the controls
   * stay where they are.
   */
  public static function processSections(array $element, FormStateInterface $form_state, array &$complete_form): array {
    $sections = $element['#neo_seo_sections'] ?? [];
    if (!$sections) {
      return $element;
    }
    uasort($sections, fn(array $a, array $b) => ($a['#weight'] ?? 0) <=> ($b['#weight'] ?? 0));
    $first = (string) array_key_first($sections);
    $children = Element::children($element);
    foreach ($sections as $id => $section) {
      $element[$id] = $section + [
        '#type' => 'details',
        '#open' => FALSE,
        '#group' => $element['#neo_seo_group'] ?? NULL,
        '#attributes' => [
          'class' => ['neo-seo-section', 'neo-seo-section--' . Html::getClass($id)],
          'data-neo-seo-section-wrapper' => $id,
        ],
      ];
      // After the field's other sections, in the sidebar's order.
      $element[$id]['#weight'] = 90 + ($section['#weight'] ?? 0);
    }
    foreach ($children as $key) {
      $id = $element[$key]['#neo_seo_section'] ?? $first;
      $target = isset($sections[$id]) ? $id : $first;
      $element[$key]['#parents'] ??= array_merge($element['#parents'], [$key]);
      $element[$target][$key] = $element[$key];
      unset($element[$key]);
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
    $formObject = $form_state->getFormObject();
    $entity = $formObject instanceof EntityFormInterface ? $formObject->getEntity() : NULL;
    $templates = $entity instanceof ContentEntityInterface ? $this->snippet->templates($entity) : [];
    foreach ($values as &$value) {
      // A title or description that is just the template it overrides is no
      // override: later template changes still reach the page.
      foreach (['title', 'description'] as $key) {
        $value[$key] = Snippet::override($value[$key] ?? NULL, $templates[$key] ?? '');
      }
      $value['image'] = !empty($value['image']) ? (int) $value['image'] : NULL;
      foreach (['page_type', 'article_type', 'title', 'description', 'keyphrase', 'slug'] as $key) {
        $value[$key] = ($value[$key] ?? '') === '' ? NULL : $value[$key];
      }
      // The URL controls write the page's path (PageUrlHooks), not the field.
      unset($value['url_custom'], $value['url_alias'], $value['url_preview']);
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
