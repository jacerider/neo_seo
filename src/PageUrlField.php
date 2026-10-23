<?php

declare(strict_types=1);

namespace Drupal\neo_seo;

use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Render\ElementInfoManagerInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * The page URL field: the slug, or a URL of the page's own (ADR 0005).
 *
 * Its own element, apart from the SEO overrides: who may change a page's URL
 * is who may create URL aliases, as for core's URL alias field, whether or
 * not they may edit the overrides. The edit form shows it as a URL section of
 * the sidebar (PageUrlHooks); the Alchemist SEO panel shows it under the
 * previews, for editors who may also open that panel (neo_seo_panel). Its
 * controls are named as the overrides' are (neo_seo[0][slug]), so the slug is
 * saved with them and the SEO panel finds every control the same way.
 */
class PageUrlField {

  use StringTranslationTrait;

  /**
   * Constructs the service.
   *
   * @param \Drupal\neo_seo\PageUrl $pageUrl
   *   The page URL service.
   * @param \Drupal\Core\Render\ElementInfoManagerInterface $elementInfo
   *   The element info manager, for a textfield's own process callbacks.
   */
  public function __construct(
    protected PageUrl $pageUrl,
    protected ElementInfoManagerInterface $elementInfo,
  ) {}

  /**
   * Whether an account gets the URL field on a page.
   *
   * Where neo_seo manages the page's URL, to those core lets edit its URL
   * alias (create or administer URL aliases).
   */
  public function access(ContentEntityInterface $entity, AccountInterface $account): bool {
    return $this->pageUrl->manages($entity) && $this->pageUrl->mayChange($entity, $account);
  }

  /**
   * The URL field for a page.
   *
   * An Automatic | Custom choice leads: the slug shows under Automatic, where
   * the pattern holds [neo:slug], else the address the site sets, read-only;
   * the custom URL under Custom, to those who may set one, and read-only to
   * anyone else on a page that already has one, who may still choose
   * Automatic to hand it back to the pattern. Under them, what to know about
   * the URL the page will have once saved, which the SEO panel keeps current
   * from its renders.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The page, saved or a draft of it.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Who is editing it.
   * @param array|null $parents
   *   Where the values go; by default the overrides' item (['neo_seo', 0]).
   */
  public function build(ContentEntityInterface $entity, AccountInterface $account, ?array $parents = NULL): array {
    $parents ??= [SeoOverrides::FIELD, 0];
    $url = $this->pageUrl->describe($entity);
    $mayCustomize = $this->pageUrl->mayCustomize($entity, $account);
    $hasPattern = $this->pageUrl->pattern($entity) !== NULL;
    $usesSlug = $this->pageUrl->usesSlug($entity);
    $first = $parents;
    $name = array_shift($first) . ($first ? '[' . implode('][', $first) . ']' : '') . '[url_custom]';
    $customOn = [':input[name="' . $name . '"]' => ['value' => '1']];

    // One URL field: the choice first, then the slug or the custom URL in one
    // place, one shown at a time, with what to know about the address under
    // them, so switching moves nothing the editor is looking at.
    $field = [
      '#type' => 'container',
      '#attributes' => ['class' => ['neo-seo-url-field']],
      '#attached' => ['library' => ['neo_seo/page_url']],
    ];
    $field['actions'] = static::actions($entity);
    $field['url_custom'] = [
      '#type' => 'radios',
      '#title' => $this->t('URL'),
      '#title_display' => 'invisible',
      '#options' => [0 => $this->t('Automatic'), 1 => $this->t('Custom')],
      '#default_value' => (int) $url['custom'],
      '#access' => $hasPattern && ($mayCustomize || $url['custom']),
      '#neo_style' => 'inline_buttons',
      '#neo_size' => 'sm',
    ];
    // The slug under the address it ends, in one box: the pattern's part
    // above, read with the input (processSlug()).
    $field['slug'] = [
      '#type' => 'textfield',
      '#title' => $this->t('URL slug'),
      '#default_value' => SeoOverrides::of($entity)['slug'] ?? '',
      '#maxlength' => 255,
      // The slug an empty field follows: the title's.
      '#placeholder' => $url['placeholder'],
      '#description' => static::help(
        $this->t('The last part of this page’s address.'),
        $this->t('Leave empty to follow the title.'),
      ),
      '#access' => $usesSlug,
      '#states' => ['invisible' => $customOn],
      '#neo_seo_prefix' => $url['prefix'],
      '#attributes' => ['data-neo-seo-url-input' => 'slug'],
      '#wrapper_attributes' => ['class' => ['neo-seo-url-slug']],
      '#process' => array_merge(
        $this->elementInfo->getInfoProperty('textfield', '#process', []),
        [[static::class, 'processSlug']],
      ),
    ];
    $field['url_alias'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Custom URL'),
      '#default_value' => $url['alias'] ?? '',
      '#maxlength' => 255,
      '#attributes' => ['data-neo-seo-url-input' => 'alias'],
      '#access' => $mayCustomize || $url['custom'],
      '#disabled' => !$mayCustomize,
      '#states' => $hasPattern ? ['visible' => $customOn] : [],
      '#description' => $mayCustomize
        ? $this->t('The whole address after the domain, starting with a slash, e.g. /about-us.')
        : $this->t('This page has an address of its own. Choose Automatic to give it the one the site sets.'),
    ];
    // A pattern without the slug: the site sets the whole address.
    $field['url_auto'] = [
      '#type' => 'item',
      '#title' => $this->t('URL'),
      '#markup' => '<span class="neo-seo-url-box__address" data-neo-seo-url-auto>' . Html::escape((string) $url['alias']) . '</span>',
      '#wrapper_attributes' => ['class' => ['neo-seo-url-box', 'is-readonly']],
      '#access' => $hasPattern && !$usesSlug,
      '#states' => ['invisible' => $customOn],
    ];
    $field['url_preview'] = static::notes($url);
    foreach (['url_custom', 'slug', 'url_alias', 'url_auto', 'url_preview'] as $key) {
      $field[$key]['#parents'] = array_merge($parents, [$key]);
    }
    return $field;
  }

  /**
   * Open the live page, and copy its address: once the page has one.
   *
   * The address it has now, not the one a draft would give it.
   */
  protected static function actions(ContentEntityInterface $entity): array {
    $url = !$entity->isNew() ? $entity->toUrl('canonical', ['absolute' => TRUE])->toString() : NULL;
    return [
      '#type' => 'inline_template',
      '#template' => '<div class="neo-seo-url-actions">'
      . '<a class="neo-seo-url-actions__action" href="{{ url }}" target="_blank" rel="noopener" title="{{ open }}" aria-label="{{ open }}">'
      . '<svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M9 2.5h4.5V7M13.5 2.5 7.5 8.5M11.5 9.5v3.5a.5.5 0 0 1-.5.5H3a.5.5 0 0 1-.5-.5V5a.5.5 0 0 1 .5-.5h3.5"/></svg>'
      . '</a>'
      . '<button type="button" class="neo-seo-url-actions__action" data-neo-seo-url-copy="{{ url }}" title="{{ copy }}" aria-label="{{ copy }}">'
      . '<svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><rect x="5.5" y="5.5" width="8" height="8" rx="1"/><path d="M10.5 3.5V3a.5.5 0 0 0-.5-.5H3a.5.5 0 0 0-.5.5v7a.5.5 0 0 0 .5.5h.5"/></svg>'
      . '</button>'
      . '<span class="visually-hidden" role="status" data-neo-seo-url-copied></span>'
      . '</div>',
      '#context' => [
        'url' => $url,
        'open' => new TranslatableMarkup('Open the page in a new tab'),
        'copy' => new TranslatableMarkup('Copy the page’s address'),
      ],
      '#access' => $url !== NULL,
    ];
  }

  /**
   * The slug input's address: the pattern's part, stacked above it.
   *
   * In the field prefix, which the input reads first (aria-describedby), so
   * a screen reader hears the whole address. The SEO panel redraws it from
   * each draft render (data-neo-seo-url-prefix).
   */
  public static function processSlug(array $element): array {
    $id = $element['#id'] . '--prefix';
    $element['#field_prefix'] = Markup::create('<span class="neo-seo-url-slug__prefix" id="' . $id . '" data-neo-seo-url-prefix>' . Html::escape((string) ($element['#neo_seo_prefix'] ?? '')) . '</span>');
    $describedBy = $element['#attributes']['aria-describedby'] ?? NULL;
    $element['#attributes']['aria-describedby'] = $describedBy ? $id . ' ' . $describedBy : $id;
    return $element;
  }

  /**
   * What an editor should know about the URL the page will have once saved.
   *
   * The SEO panel redraws it from each draft render (neo_seo_panel).
   *
   * @param array $url
   *   The page's URL, as PageUrl::describe() gives it.
   */
  public static function notes(array $url): array {
    $notes = [];
    foreach ($url['notes'] as $note) {
      // Text, its placeholders escaped by the translation.
      $notes[] = [
        '#markup' => $note['text'],
        '#wrapper_attributes' => ['class' => $note['error'] ? ['is-error'] : []],
      ];
    }
    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['neo-seo-url'],
        'data-neo-seo-url' => '',
        'aria-live' => 'polite',
      ],
      '#attached' => ['library' => ['neo_seo/page_url']],
      'notes' => [
        '#theme' => 'item_list',
        '#items' => $notes,
        '#attributes' => ['class' => ['neo-seo-url__notes']],
        '#access' => (bool) $notes,
      ],
    ];
  }

  /**
   * A control's help, as the SEO overrides give theirs: hint, then more.
   */
  protected static function help(TranslatableMarkup $hint, TranslatableMarkup $more): Markup {
    return Markup::create('<strong>' . Html::escape((string) $hint) . '</strong> ' . Html::escape((string) $more));
  }

}
