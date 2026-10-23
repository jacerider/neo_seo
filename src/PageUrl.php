<?php

declare(strict_types=1);

namespace Drupal\neo_seo;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Menu\MenuLinkManagerInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\Core\Utility\Token;
use Drupal\neo_seo\Mapping\BundleMappingRepository;
use Drupal\path_alias\AliasRepositoryInterface;
use Drupal\pathauto\AliasCleanerInterface;
use Drupal\pathauto\PathautoGeneratorInterface;

/**
 * A page's URL: the slug an editor may change, or a URL of their own.
 *
 * Pathauto builds a page's alias from its pattern, and a pattern holding
 * [neo:slug] lets editors change that part of it (ADR 0005). With
 * [neo:parents:join-path] a page's parents give their slugs too, and a page
 * whose segment changes takes the pages under it along (cascade()). A bundle
 * may also let them replace the whole alias — a custom URL, stored the way
 * core stores any alias, with pathauto turned off for the page. On a bundle
 * neo_seo manages, its URL controls replace core's path widget.
 *
 * Pathauto and path are optional: without pathauto there is no slug, only a
 * custom URL where the bundle allows one.
 */
class PageUrl {

  use StringTranslationTrait;

  /**
   * The token a pattern holds where editors may change the alias.
   */
  public const TOKEN = '[neo:slug]';

  /**
   * The token for a page's parents: each one's slug, else its title.
   */
  public const PARENTS_TOKEN = '[neo:parents:join-path]';

  /**
   * The URL values the controls send besides the slug: not overrides.
   */
  public const KEYS = ['url_custom', 'url_alias'];

  /**
   * Stands in for the slug, to find where it lands in the alias.
   */
  protected const MARKER = 'neoseoslugmarker';

  /**
   * Pathauto's states (PathautoState), here so nothing loads its class.
   */
  protected const SKIP = 0;
  protected const CREATE = 1;

  /**
   * The custom URL each page was sent, before it was cleaned (apply()).
   *
   * @var \WeakMap<\Drupal\Core\Entity\ContentEntityInterface, string>
   */
  protected \WeakMap $sent;

  /**
   * Constructs the service.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\neo_seo\Mapping\BundleMappingRepository $mapping
   *   The bundle mapping repository.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Utility\Token $token
   *   The token service, to find a page's menu link as pathauto's tokens do.
   * @param \Drupal\Core\Menu\MenuLinkManagerInterface $menuLinkManager
   *   The menu link manager.
   * @param \Drupal\path_alias\AliasRepositoryInterface|null $aliasRepository
   *   The alias repository, when path_alias is installed.
   * @param \Drupal\pathauto\PathautoGeneratorInterface|null $generator
   *   The pathauto generator, when pathauto is installed.
   * @param \Drupal\pathauto\AliasCleanerInterface|null $aliasCleaner
   *   Pathauto's alias cleaner, when pathauto is installed.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected BundleMappingRepository $mapping,
    protected ModuleHandlerInterface $moduleHandler,
    protected ConfigFactoryInterface $configFactory,
    protected Token $token,
    protected MenuLinkManagerInterface $menuLinkManager,
    protected ?AliasRepositoryInterface $aliasRepository = NULL,
    protected ?PathautoGeneratorInterface $generator = NULL,
    protected ?AliasCleanerInterface $aliasCleaner = NULL,
  ) {
    $this->sent = new \WeakMap();
  }

  /**
   * The pathauto pattern a page's alias is built from, if any.
   */
  public function pattern(ContentEntityInterface $entity): ?string {
    return $this->generator?->getPatternByEntity($entity)?->getPattern();
  }

  /**
   * Whether a page's pattern lets editors change its slug.
   */
  public function usesSlug(ContentEntityInterface $entity): bool {
    return str_contains((string) $this->pattern($entity), self::TOKEN);
  }

  /**
   * Whether neo_seo's URL controls stand in for core's path widget.
   *
   * On a page with SEO overrides and a path whose bundle lets editors change
   * something: the slug (its pattern holds the token) or the whole URL.
   */
  public function manages(ContentEntityInterface $entity): bool {
    return $entity->hasField(SeoOverrides::FIELD)
      && $entity->hasField('path')
      && ($this->usesSlug($entity) || $this->bundleAllowsCustom($entity));
  }

  /**
   * Whether an account may change a page's URL at all: its slug, or more.
   *
   * Who core lets edit a page's URL alias: those who may create or
   * administer URL aliases, whether or not they may edit the SEO overrides.
   */
  public function mayChange(ContentEntityInterface $entity, AccountInterface $account): bool {
    return $entity->hasField('path') && $entity->get('path')->access('edit', $account);
  }

  /**
   * Whether an account may give a page a URL of its own.
   *
   * Where the bundle allows it, to those who may create aliases; anywhere, to
   * those who administer them.
   */
  public function mayCustomize(ContentEntityInterface $entity, AccountInterface $account): bool {
    return $account->hasPermission('administer url aliases')
      || ($this->bundleAllowsCustom($entity) && $account->hasPermission('create url aliases'));
  }

  /**
   * Whether a page has a URL of its own rather than its pattern's.
   *
   * Without a pattern every alias is the page's own.
   */
  public function isCustom(ContentEntityInterface $entity): bool {
    if ($this->pattern($entity) === NULL) {
      return TRUE;
    }
    $item = $entity->get('path')->first();
    return $item && isset($item->getProperties(TRUE)['pathauto']) && (int) $item->get('pathauto')->getValue() === self::SKIP;
  }

  /**
   * The alias a page will have when saved, and how it is made up.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The page, saved or a draft of it.
   *
   * @return array{custom: bool, alias: ?string, url: ?string, prefix: string, slug: string, input: string, placeholder: string, automatic: bool, taken: bool, wanted: ?string, saved: ?string, changed: bool, cause: ?string, keep: ?array, moving: bool, redirect: bool, children: int, notice: ?string, error: ?string, callouts: list<array>}
   *   - custom: whether it is a URL of the page's own.
   *   - alias: the alias, NULL until a new page is saved.
   *   - url: the alias as an absolute URL.
   *   - prefix: the pattern's part of the alias, before the slug.
   *   - slug: the slug as it will be saved: cleaned, but without the suffix
   *     pathauto adds when the address is another page's (all of the alias
   *     when custom).
   *   - input: what the editor sent: the slug, or the custom URL as typed.
   *   - placeholder: the slug the page's title gives, which an empty slug
   *     follows ('' where the pattern has no slug).
   *   - automatic: whether the site sets the whole alias: its pattern has no
   *     slug.
   *   - taken: whether the slug's address is another page's, so pathauto
   *     makes it unique.
   *   - wanted: the address the slug asks for, before pathauto makes it
   *     unique.
   *   - saved: the alias the page has now.
   *   - changed: whether saving moves the page.
   *   - cause: 'title' where a new title moves a page with no slug.
   *   - keep: what the URL controls hold to keep the address the page has
   *     now (url_custom, slug, url_alias), where saving would change it.
   *   - moving: whether saving moves a published page, which visitors and
   *     links may know by its old address.
   *   - redirect: whether the old URL will then redirect (the redirect module
   *     makes one).
   *   - children: how many pages under this one move with it, when its own
   *     segment (slug, else title) changes.
   *   - notice, error: what an editor should know, and what keeps a custom
   *     URL from being saved.
   *   - callouts: all an editor should know, grouped and in the order to
   *     show it (callouts()).
   */
  public function describe(ContentEntityInterface $entity): array {
    $custom = $this->isCustom($entity);
    $saved = $this->savedAlias($entity);
    $usesSlug = $this->usesSlug($entity);
    $notice = $error = NULL;
    $prefix = $slug = '';
    $wanted = NULL;
    $taken = FALSE;
    if ($custom) {
      $input = $this->sent[$entity] ?? (string) ($entity->get('path')->first()?->get('alias')->getValue() ?? '');
      $alias = $this->cleanCustom($input, $saved, $entity) ?: NULL;
      $slug = (string) $alias;
      if ($alias !== NULL && $this->taken($entity, $alias)) {
        $error = (string) $this->t('@alias is already another page’s URL.', ['@alias' => $alias]);
      }
    }
    else {
      $input = static::slugOf($entity);
      if ($saved !== NULL && (int) $this->configFactory->get('pathauto.settings')->get('update_action') === 0) {
        // Pathauto keeps every page's first alias on this site.
        $alias = $saved;
        $notice = (string) $this->t('This site keeps a page’s first URL, so a new slug only applies to new pages.');
      }
      else {
        $alias = $this->generate($entity);
        $marked = $alias !== NULL && $usesSlug ? $this->generate($entity, self::MARKER) : NULL;
        $at = $marked !== NULL ? strpos($marked, self::MARKER) : FALSE;
        if ($at !== FALSE && str_starts_with($alias, substr($marked, 0, $at))) {
          $prefix = substr($marked, 0, $at);
          // Pathauto cleans the slug as it does any token's value, then adds
          // a suffix where the address is another page's: the editor's slug
          // stays clean, and the suffix is only noted.
          $slug = $this->clean(static::segment($entity), $entity);
          $wanted = $prefix . $slug . substr($marked, $at + strlen(self::MARKER));
          $taken = $alias !== $wanted;
        }
      }
    }
    $changed = $saved !== NULL && $alias !== NULL && $alias !== $saved;
    $moving = $changed && $error === NULL && (!$entity instanceof EntityPublishedInterface || $entity->isPublished());
    $before = $changed ? $this->savedVersion($entity) : NULL;
    // A page with no slug, before or now, moved by a new title.
    $cause = $before && !$custom && $input === '' && static::slugOf($before) === '' && $before->label() !== $entity->label() ? 'title' : NULL;
    $url = [
      'custom' => $custom,
      'alias' => $alias,
      'url' => $alias !== NULL ? $this->absolute($entity, $alias) : NULL,
      'prefix' => $prefix,
      'slug' => $slug,
      'input' => $input,
      'placeholder' => $usesSlug ? $this->clean((string) $entity->label(), $entity) : '',
      'automatic' => !$custom && !$usesSlug,
      'taken' => $taken,
      'wanted' => $wanted,
      'saved' => $saved,
      'changed' => $changed,
      'cause' => $cause,
      'keep' => $before ? $this->keep($before, (string) $saved, $prefix, $cause) : NULL,
      'moving' => $moving,
      'redirect' => $moving && $this->redirects(),
      'children' => $this->segmentChanged($entity) ? count($this->descendants($entity)) : 0,
      'notice' => $notice,
      'error' => $error,
    ];
    return $url + ['callouts' => $this->callouts($url)];
  }

  /**
   * What the URL controls hold to keep the address a page has now.
   *
   * The values they would show for the page as saved. Where a new title
   * moves a page with no slug, putting them back changes nothing, so the
   * slug pins the address instead: its last part as it is now.
   */
  protected function keep(ContentEntityInterface $saved, string $alias, string $prefix, ?string $cause): array {
    if ($cause === 'title') {
      $slug = $prefix !== '' && str_starts_with($alias, $prefix) ? substr($alias, strlen($prefix)) : basename($alias);
      return ['url_custom' => FALSE, 'slug' => $slug, 'url_alias' => $alias];
    }
    return [
      'url_custom' => $this->isCustom($saved),
      'slug' => static::slugOf($saved),
      'url_alias' => $alias,
    ];
  }

  /**
   * What an editor should know about a page's URL, grouped into callouts.
   *
   * A move is one callout: from where to where, why (a new title), whether
   * the slug's address was taken, what happens to links to the old address
   * (a published page), and the pages under it that move too — warning where
   * links will break or pathauto changes the slug, else information. Anything
   * else is a callout of its own: an error, a warning or information.
   *
   * @param array $url
   *   The page's URL, as describe() gives it.
   *
   * @return list<array{kind: string, tone: string, lines: list<array{icon: string, text: string}>, cause?: ?string, from?: string, to?: string, keep?: ?array}>
   *   Each callout. Its texts are HTML, escaped by the translation.
   */
  protected function callouts(array $url): array {
    $line = fn(string $icon, $text): array => ['icon' => $icon, 'text' => (string) $text];
    $taken = $url['taken']
      ? $line('taken', $this->t('Another page is @wanted, so this one gets @suffix.', [
        '@wanted' => $url['wanted'],
        '@suffix' => substr((string) $url['alias'], strlen((string) $url['wanted'])),
      ]))
      : NULL;
    $children = $url['children']
      ? $line('children', $this->formatPlural($url['children'], 'The page under this one moves with it.', 'The @count pages under this one move with it.'))
      : NULL;
    $callouts = [];
    if ($url['error']) {
      $callouts[] = ['kind' => 'error', 'tone' => 'error', 'lines' => [$line('error', $url['error'])]];
    }
    if ($url['changed'] && !$url['error']) {
      $lines = array_filter([$taken]);
      if ($url['moving']) {
        $lines[] = $url['redirect']
          ? $line('redirect', $this->t('The old address will redirect here.'))
          : $line('broken', $this->t('Links to the old address will stop working.'));
      }
      $lines[] = $children;
      $callouts[] = [
        'kind' => 'move',
        'tone' => $taken || ($url['moving'] && !$url['redirect']) ? 'warning' : 'info',
        'cause' => $url['cause'] === 'title' ? (string) $this->t('Because the title changed.') : NULL,
        'from' => $url['saved'],
        'to' => $url['alias'],
        'lines' => array_values(array_filter($lines)),
        'keep' => $url['keep'],
      ];
      $taken = $children = NULL;
    }
    if ($taken) {
      $callouts[] = ['kind' => 'taken', 'tone' => 'warning', 'lines' => [$taken]];
    }
    if ($children) {
      $callouts[] = ['kind' => 'children', 'tone' => 'info', 'lines' => [$children]];
    }
    $info = array_filter([
      'new' => !$url['error'] && $url['alias'] === NULL ? $this->t('The address is set when the page is first saved.') : NULL,
      'notice' => $url['notice'],
      'automatic' => $url['automatic'] && $url['alias'] !== NULL ? $this->t('The site sets this page’s address.') : NULL,
    ]);
    foreach ($info as $kind => $text) {
      $callouts[] = ['kind' => $kind, 'tone' => 'info', 'lines' => [$line('info', $text)]];
    }
    return $callouts;
  }

  /**
   * A page as it is saved, in the same language; NULL for a new page.
   */
  protected function savedVersion(ContentEntityInterface $entity): ?ContentEntityInterface {
    if ($entity->isNew()) {
      return NULL;
    }
    $saved = $this->entityTypeManager->getStorage($entity->getEntityTypeId())->loadUnchanged($entity->id());
    if ($saved instanceof ContentEntityInterface && $saved->hasTranslation($entity->language()->getId())) {
      $saved = $saved->getTranslation($entity->language()->getId());
    }
    return $saved instanceof ContentEntityInterface ? $saved : NULL;
  }

  /**
   * Applies what the URL controls sent to a page's path, without saving it.
   *
   * What an account may not do is dropped, so the path stays as it was:
   * anything from one who may not change the page's URL (mayChange()), a
   * custom URL from one who may not set one, or one that is another page's.
   * Anyone who may change the URL may hand a custom URL back to the pattern.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The page; changed in memory.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Who sent the values.
   * @param array $values
   *   The values: url_custom (bool) and url_alias (string), either optional.
   */
  public function apply(ContentEntityInterface $entity, AccountInterface $account, array $values): void {
    $hasCustom = array_key_exists('url_custom', $values);
    $hasAlias = array_key_exists('url_alias', $values);
    if ((!$hasCustom && !$hasAlias) || !$this->manages($entity) || !$this->mayChange($entity, $account)) {
      return;
    }
    $pattern = $this->pattern($entity) !== NULL;
    $custom = !$pattern || ($hasCustom ? (bool) $values['url_custom'] : $this->isCustom($entity));
    if (!$custom) {
      if ($this->isCustom($entity)) {
        $this->setPath($entity, NULL);
      }
      return;
    }
    if (!$this->mayCustomize($entity, $account)) {
      return;
    }
    // Turning a custom URL on without one keeps the page where it is. What
    // was typed is cleaned here too, so a posted draft cannot skip it.
    $sent = $hasAlias && is_scalar($values['url_alias']) ? (string) $values['url_alias'] : NULL;
    $alias = $sent !== NULL
      ? $this->cleanCustom($sent, $this->savedAlias($entity), $entity)
      : (string) ($this->describe($entity)['alias'] ?? '');
    if ($sent !== NULL) {
      $this->sent[$entity] = $sent;
    }
    if ($alias === '' && $pattern) {
      $this->setPath($entity, NULL);
    }
    elseif ($alias === '' || !$this->taken($entity, $alias)) {
      $this->setPath($entity, $alias);
    }
  }

  /**
   * A page's own segment, before pathauto cleans it: its slug, else its label.
   */
  public static function segment(EntityInterface $entity): string {
    $slug = static::slugOf($entity);
    return $slug !== '' ? $slug : (string) $entity->label();
  }

  /**
   * The slug an editor set for a page, or ''.
   */
  public static function slugOf(?EntityInterface $entity): string {
    return $entity instanceof ContentEntityInterface ? trim((string) (SeoOverrides::of($entity)['slug'] ?? '')) : '';
  }

  /**
   * A page's parents' segments, outermost first, before pathauto cleans them.
   *
   * A content page's parents are its menu link's (the link pathauto's own
   * menu tokens use); a term's are its parent terms. Each is the parent's
   * slug, else what core's parents tokens give — the menu link's title, the
   * term's name — so a site without slugs gets the aliases it has today. A
   * parent's custom URL is its own and gives its children nothing.
   *
   * @return string[]
   *   The segments.
   */
  public function parents(EntityInterface $entity, ?string $langcode = NULL): array {
    $langcode ??= $entity->language()->getId();
    $segments = [];
    if ($entity->getEntityTypeId() === 'taxonomy_term' && !$entity->isNew()) {
      $storage = $this->entityTypeManager->getStorage('taxonomy_term');
      foreach (token_taxonomy_term_load_all_parents($entity->id(), $langcode) as $tid => $name) {
        $slug = static::slugOf($storage->load($tid));
        $segments[] = $slug !== '' ? $slug : (string) $name;
      }
    }
    elseif ($id = $this->menuLinkId($entity)) {
      foreach (token_menu_link_load_all_parents($id, $langcode) as $parentId => $title) {
        $slug = static::slugOf($this->linkedEntity((string) $parentId));
        $segments[] = $slug !== '' ? $slug : (string) $title;
      }
    }
    return $segments;
  }

  /**
   * The pages whose addresses follow this one's.
   *
   * Its menu descendants, or its descendant terms, that follow their pattern;
   * a page with a custom URL keeps it.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface[]
   *   The pages.
   */
  public function descendants(ContentEntityInterface $entity): array {
    if (!$this->generator || $entity->isNew()) {
      return [];
    }
    $found = [];
    if ($entity->getEntityTypeId() === 'taxonomy_term') {
      $found = $this->entityTypeManager->getStorage('taxonomy_term')->loadTree($entity->bundle(), (int) $entity->id(), NULL, TRUE);
    }
    elseif ($id = $this->menuLinkId($entity)) {
      foreach ($this->menuLinkManager->getChildIds($id) as $childId) {
        $found[] = $this->linkedEntity((string) $childId);
      }
    }
    return array_values(array_filter($found, fn($page) => $page instanceof ContentEntityInterface
      && $page->hasField('path')
      && $this->pattern($page) !== NULL
      && !$this->isCustom($page)));
  }

  /**
   * Re-aliases the pages under a page, after its segment changed.
   *
   * Pathauto re-aliases a term's children when it re-aliases the term, but
   * never a page's menu children, and not a term's children while the term
   * itself keeps a custom URL.
   *
   * @return int
   *   How many pages were re-aliased.
   */
  public function cascade(ContentEntityInterface $entity): int {
    $this->forgetParents();
    $pages = $this->descendants($entity);
    array_walk($pages, [$this, 'realias']);
    return count($pages);
  }

  /**
   * Re-aliases one page from its pattern, unless it keeps a custom URL.
   */
  public function realias(ContentEntityInterface $page): void {
    $this->forgetParents();
    $this->generator?->updateEntityAlias($page, 'update');
  }

  /**
   * Forgets the parents the token module remembered earlier in the request.
   *
   * A menu link saved in this request may have moved since they were read.
   */
  protected function forgetParents(): void {
    drupal_static_reset('token_menu_link_load_all_parents');
    drupal_static_reset('token_taxonomy_term_load_all_parents');
  }

  /**
   * Whether saving a page changes its segment, and so its children's.
   */
  public function segmentChanged(ContentEntityInterface $entity): bool {
    $saved = $this->savedVersion($entity);
    return $saved !== NULL && static::segment($saved) !== static::segment($entity);
  }

  /**
   * The page a menu link points at, if it is an entity's canonical page.
   */
  public function linkedEntity(string $pluginId): ?ContentEntityInterface {
    try {
      $url = $this->menuLinkManager->createInstance($pluginId)->getUrlObject();
    }
    catch (\Exception) {
      return NULL;
    }
    if (!$url->isRouted() || !preg_match('/^entity\.(\w+)\.canonical$/', $url->getRouteName(), $match)) {
      return NULL;
    }
    $id = $url->getRouteParameters()[$match[1]] ?? NULL;
    $entity = $id !== NULL && $this->entityTypeManager->hasDefinition($match[1])
      ? $this->entityTypeManager->getStorage($match[1])->load($id)
      : NULL;
    return $entity instanceof ContentEntityInterface ? $entity : NULL;
  }

  /**
   * The pathauto patterns that build a bundle's aliases, keyed by label.
   *
   * @return array<string, string>
   *   Each pattern string, by its label, in the order pathauto tries them.
   */
  public function bundlePatterns(string $entityTypeId, string $bundle): array {
    if (!$this->generator || !$this->entityTypeManager->hasDefinition('pathauto_pattern')) {
      return [];
    }
    /** @var \Drupal\pathauto\PathautoPatternInterface[] $patterns */
    $patterns = $this->entityTypeManager->getStorage('pathauto_pattern')->loadByProperties([
      'type' => 'canonical_entities:' . $entityTypeId,
      'status' => TRUE,
    ]);
    uasort($patterns, fn($a, $b) => $a->getWeight() <=> $b->getWeight());
    $found = [];
    foreach ($patterns as $pattern) {
      $bundles = PageUrlPatterns::bundlesOf($pattern);
      if ($bundles === NULL || in_array($bundle, $bundles, TRUE)) {
        $found[(string) $pattern->label()] = $pattern->getPattern();
      }
    }
    return $found;
  }

  /**
   * A custom URL as it is saved: each segment cleaned like a slug.
   *
   * Lowercase, punctuation removed and spaces made hyphens, by pathauto's
   * own rules where it is installed; one leading slash and none trailing.
   * "/About Us/Team!" is "/about-us/team".
   */
  public function normalize(string $alias, ?string $langcode = NULL): string {
    $segments = [];
    foreach (explode('/', $alias) as $segment) {
      $segment = $this->aliasCleaner
        ? $this->aliasCleaner->cleanString($segment, ['langcode' => $langcode ?? 'en'])
        : trim(preg_replace('/[^\p{L}\p{N}]+/u', '-', mb_strtolower($segment)) ?? '', '-');
      if ($segment !== '') {
        $segments[] = $segment;
      }
    }
    return $segments ? '/' . implode('/', $segments) : '';
  }

  /**
   * A custom URL as typed, cleaned unless it is the one the page has.
   *
   * A page's existing address is left alone, however it is written: cleaning
   * it would move a page no one asked to move.
   */
  protected function cleanCustom(string $typed, ?string $saved, EntityInterface $entity): string {
    $typed = trim($typed);
    return $saved !== NULL && $typed === $saved ? $saved : $this->normalize($typed, $entity->language()->getId());
  }

  /**
   * Text as pathauto cleans a token's value into a slug.
   */
  protected function clean(string $text, EntityInterface $entity): string {
    return $this->aliasCleaner?->cleanString($text, ['langcode' => $entity->language()->getId()]) ?? $text;
  }

  /**
   * The menu link pathauto's tokens use for a content page, if any.
   */
  protected function menuLinkId(EntityInterface $entity): ?string {
    if ($entity->getEntityTypeId() !== 'node' || $entity->isNew()) {
      return NULL;
    }
    $id = $this->token->replace('[node:menu-link:id]', ['node' => $entity], ['clear' => TRUE], new BubbleableMetadata());
    return $id !== '' ? $id : NULL;
  }

  /**
   * Whether the bundle lets editors give a page a URL of its own.
   */
  protected function bundleAllowsCustom(ContentEntityInterface $entity): bool {
    return $this->allowsCustom($entity->getEntityTypeId(), $entity->bundle());
  }

  /**
   * Whether a bundle lets editors give its pages URLs of their own.
   */
  public function allowsCustom(string $entityTypeId, string $bundle): bool {
    return $this->mapping->forBundle($entityTypeId, $bundle)->customUrl;
  }

  /**
   * The alias pathauto would build for a page, with an optional slug.
   *
   * NULL for a new page, which pathauto cannot make unique until it has a
   * path of its own.
   */
  protected function generate(ContentEntityInterface $entity, ?string $slug = NULL): ?string {
    if (!$this->generator || $entity->isNew()) {
      return NULL;
    }
    if ($slug !== NULL) {
      $entity = clone $entity;
      $entity->set(SeoOverrides::FIELD, ['slug' => $slug] + SeoOverrides::of($entity));
    }
    $alias = $this->generator->createEntityAlias($entity, 'return');
    return is_string($alias) && $alias !== '' ? $alias : NULL;
  }

  /**
   * The alias a saved page has now, if any.
   */
  protected function savedAlias(ContentEntityInterface $entity): ?string {
    if ($entity->isNew() || !$this->aliasRepository) {
      return NULL;
    }
    $found = $this->aliasRepository->lookupBySystemPath($this->systemPath($entity), $entity->language()->getId());
    return $found['alias'] ?? NULL;
  }

  /**
   * Whether an alias is already another page's.
   */
  protected function taken(ContentEntityInterface $entity, string $alias): bool {
    $found = $this->aliasRepository?->lookupByAlias($alias, $entity->language()->getId());
    return $found !== NULL && ($entity->isNew() || $found['path'] !== $this->systemPath($entity));
  }

  /**
   * Whether the redirect module will send a moved page's old URL on.
   */
  protected function redirects(): bool {
    return $this->moduleHandler->moduleExists('redirect')
      && $this->configFactory->get('redirect.settings')->get('auto_redirect');
  }

  /**
   * Sets a page's path: a URL of its own, or NULL to hand it to the pattern.
   */
  protected function setPath(ContentEntityInterface $entity, ?string $alias): void {
    $items = $entity->get('path');
    $item = $items->first() ?? $items->appendItem();
    $pathauto = isset($item->getProperties(TRUE)['pathauto']);
    if ($alias === NULL) {
      if ($pathauto) {
        $item->set('pathauto', self::CREATE);
      }
      return;
    }
    $item->set('alias', $alias);
    if ($pathauto) {
      $item->set('pathauto', self::SKIP);
    }
  }

  /**
   * A page's system path, such as /node/1.
   */
  protected function systemPath(ContentEntityInterface $entity): string {
    return '/' . $entity->toUrl()->getInternalPath();
  }

  /**
   * An alias as an absolute URL, in the page's language.
   */
  protected function absolute(ContentEntityInterface $entity, string $alias): string {
    return Url::fromUri('base:' . ltrim($alias, '/'), [
      'absolute' => TRUE,
      'language' => $entity->language(),
    ])->toString();
  }

}
