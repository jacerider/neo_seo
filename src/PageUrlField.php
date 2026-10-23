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
   * The icons the field shows, from neo_icon's own regular library.
   */
  protected const ICONS = [
    'open' => 'arrow-up-right-from-square',
    'copy' => 'copy',
    'move' => 'arrow-right',
    'redirect' => 'arrow-turn-down-right',
    'broken' => 'link-slash',
    'taken' => 'clone',
    'children' => 'folder-tree',
    'error' => 'circle-xmark',
    'info' => 'circle-info',
  ];

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
   * @param bool $publish
   *   Whether changes go live on Publish (Alchemist), not on Save.
   */
  public function build(ContentEntityInterface $entity, AccountInterface $account, ?array $parents = NULL, bool $publish = FALSE): array {
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
    $field['url_preview'] = static::notes($url, $publish);
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
      . '<a class="neo-seo-url-actions__action" href="{{ url }}" target="_blank" rel="noopener" title="{{ open }}" aria-label="{{ open }}">{{ open_icon }}</a>'
      . '<button type="button" class="neo-seo-url-actions__action" data-neo-seo-url-copy="{{ url }}" title="{{ copy }}" aria-label="{{ copy }}">{{ copy_icon }}</button>'
      . '<span class="visually-hidden" role="status" data-neo-seo-url-copied></span>'
      . '</div>',
      '#context' => [
        'url' => $url,
        'open' => new TranslatableMarkup('Open the page in a new tab'),
        'copy' => new TranslatableMarkup('Copy the page’s address'),
        'open_icon' => static::icon('open'),
        'copy_icon' => static::icon('copy'),
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
   * PageUrl::describe()'s callouts, each a box toned by what it means: a move
   * from the old address to the new (when saved, or when published in
   * Alchemist), with why and what happens to links, and a button that keeps
   * the address the page has now; errors, warnings and information apart.
   * The SEO panel replaces it with each draft render's (neo_seo_panel).
   *
   * @param array $url
   *   The page's URL, as PageUrl::describe() gives it.
   * @param bool $publish
   *   Whether the change goes live on Publish (Alchemist), not on Save.
   */
  public static function notes(array $url, bool $publish = FALSE): array {
    $callouts = [];
    foreach ($url['callouts'] as $callout) {
      // Texts are HTML already, their placeholders escaped by the translation.
      foreach ($callout['lines'] as &$line) {
        $line['text'] = Markup::create($line['text']);
        $line['icon'] = static::icon($line['icon']);
      }
      unset($line);
      if (!empty($callout['cause'])) {
        $callout['cause'] = Markup::create($callout['cause']);
      }
      if ($callout['kind'] === 'move') {
        $callout['from'] = static::address((string) $callout['from']);
        $callout['to'] = static::address((string) $callout['to']);
        $callout['keep'] = $callout['keep'] ? json_encode($callout['keep']) : NULL;
      }
      $callouts[] = $callout;
    }
    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['neo-seo-url'],
        'data-neo-seo-url' => '',
        'aria-live' => 'polite',
      ],
      '#attached' => ['library' => ['neo_seo/page_url']],
      'callouts' => [
        '#type' => 'inline_template',
        '#template' => static::TEMPLATE,
        '#context' => [
          'callouts' => $callouts,
          'heading' => $publish
            ? new TranslatableMarkup('Address changes when published')
            : new TranslatableMarkup('Address changes when saved'),
          'from_label' => new TranslatableMarkup('Old address:'),
          'to_label' => new TranslatableMarkup('New address:'),
          'keep_label' => new TranslatableMarkup('Keep current address'),
          'move_icon' => static::icon('move'),
        ],
      ],
    ];
  }

  /**
   * An address for one line: its start, which may be cut, and its end.
   *
   * The end (the part that changed, usually) always shows; the start gives
   * way with an ellipsis in the middle (page-url.css). The whole address is
   * still the text, for screen readers, and its title.
   *
   * @return array{full: string, start: string, end: string}
   *   The parts.
   */
  protected static function address(string $address): array {
    // The last segment, or as much of its end as fits, from a word's start.
    $tail = mb_substr($address, -24);
    $break = max((int) strrpos($tail, '/'), 0) ?: strpos($tail, '-');
    $tail = mb_strlen($address) > 24 && $break !== FALSE ? substr($tail, $break + (int) ($tail[$break] === '-')) : $tail;
    return [
      'full' => $address,
      'start' => mb_substr($address, 0, mb_strlen($address) - mb_strlen($tail)),
      'end' => $tail,
    ];
  }

  /**
   * One of the field's icons, beside text that says what it means.
   */
  protected static function icon(string $key): array {
    return [
      '#type' => 'neo_icon',
      '#icon' => static::ICONS[$key],
      '#icon_library' => 'regular',
      '#icon_attributes' => [
        'class' => ['neo-seo-url-icon', 'neo-seo-url-icon--' . $key],
        'aria-hidden' => 'true',
      ],
    ];
  }

  /**
   * The callouts' markup.
   */
  protected const TEMPLATE = <<<'TWIG'
{% for callout in callouts %}
  <div class="neo-seo-url-callout is-{{ callout.tone }}" data-kind="{{ callout.kind }}"{% if callout.tone == 'error' %} role="alert"{% endif %}>
    {% if callout.kind == 'move' %}
      <p class="neo-seo-url-callout__heading">{{ move_icon }}<span>{{ heading }}</span></p>
      {% if callout.cause %}<p class="neo-seo-url-callout__cause">{{ callout.cause }}</p>{% endif %}
      <dl class="neo-seo-url-diff">
        {% for row in [{class: 'is-from', mark: '−', label: from_label, address: callout.from}, {class: 'is-to', mark: '+', label: to_label, address: callout.to}] %}
          <div class="neo-seo-url-diff__row {{ row.class }}">
            <dt><span aria-hidden="true">{{ row.mark }}</span><span class="visually-hidden">{{ row.label }}</span></dt>
            <dd class="neo-seo-url-address" title="{{ row.address.full }}"><span class="neo-seo-url-address__start">{{ row.address.start }}</span><span class="neo-seo-url-address__end">{{ row.address.end }}</span></dd>
          </div>
        {% endfor %}
      </dl>
    {% endif %}
    {% for line in callout.lines %}
      <p class="neo-seo-url-callout__line">{{ line.icon }}<span>{{ line.text }}</span></p>
    {% endfor %}
    {% if callout.keep %}
      <button type="button" class="neo-seo-url-callout__keep" data-neo-seo-url-keep="{{ callout.keep }}">{{ keep_label }}</button>
    {% endif %}
  </div>
{% endfor %}
TWIG;

  /**
   * A control's help, as the SEO overrides give theirs: hint, then more.
   */
  protected static function help(TranslatableMarkup $hint, TranslatableMarkup $more): Markup {
    return Markup::create('<strong>' . Html::escape((string) $hint) . '</strong> ' . Html::escape((string) $more));
  }

}
