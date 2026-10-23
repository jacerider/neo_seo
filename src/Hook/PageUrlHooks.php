<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Hook;

use Drupal\Core\Entity\ContentEntityFormInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\neo_seo\PageUrl;
use Drupal\neo_seo\PageUrlField;
use Drupal\neo_seo\SeoOverrides;

/**
 * The page URL: neo_seo's controls in the edit form, and the cascade.
 *
 * A page's children carry its segment in their addresses ([neo:parents]), so
 * when its slug or title changes, or its menu link moves or is renamed, the
 * pages under it are re-aliased (PageUrl::cascade()).
 */
class PageUrlHooks {

  use StringTranslationTrait;

  /**
   * Constructs the hooks.
   *
   * @param \Drupal\neo_seo\PageUrl $pageUrl
   *   The page URL service.
   * @param \Drupal\neo_seo\PageUrlField $pageUrlField
   *   The page URL field.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The current user.
   */
  public function __construct(
    protected PageUrl $pageUrl,
    protected PageUrlField $pageUrlField,
    protected AccountInterface $currentUser,
  ) {}

  /**
   * Implements hook_form_alter().
   *
   * Where neo_seo manages the page's URL, core's path widget is hidden,
   * whether or not the form display shows it: neo_seo's URL field is the one
   * place the URL is changed. It is a section of the sidebar of its own,
   * where core's URL alias section was, for whoever core lets edit the URL
   * alias (PageUrlField::access()). Its values are applied to the page as it
   * is built, so the draft render sees them too.
   */
  #[Hook('form_alter')]
  public function formAlter(array &$form, FormStateInterface $form_state, string $form_id): void {
    $formObject = $form_state->getFormObject();
    if (!$formObject instanceof ContentEntityFormInterface) {
      return;
    }
    $entity = $formObject->getEntity();
    if (!$entity instanceof ContentEntityInterface || !$this->pageUrl->manages($entity)) {
      return;
    }
    if (isset($form['path'])) {
      $form['path']['#access'] = FALSE;
    }
    if (!$this->pageUrlField->access($entity, $this->currentUser)) {
      return;
    }
    $form['neo_seo_url'] = [
      '#type' => 'details',
      '#title' => $this->t('URL'),
      '#open' => FALSE,
      '#group' => isset($form['advanced']) ? 'advanced' : NULL,
      // Where core's URL alias section was, beside URL redirects.
      '#weight' => 30,
      '#attributes' => [
        'class' => ['neo-seo-section', 'neo-seo-section--url'],
        // A root of the SEO panel's controls (neo_seo_panel).
        'data-neo-seo-section-wrapper' => 'url',
      ],
      'url_field' => $this->pageUrlField->build($entity, $this->currentUser),
    ];
    $form['#entity_builders'][] = [static::class, 'buildEntity'];
  }

  /**
   * Implements hook_entity_update().
   *
   * A page whose segment (slug, else title) changed takes its children along.
   */
  #[Hook('entity_update')]
  public function entityUpdate(EntityInterface $entity): void {
    $original = $entity instanceof ContentEntityInterface ? $entity->getOriginal() : NULL;
    if ($original instanceof EntityInterface && PageUrl::segment($original) !== PageUrl::segment($entity)) {
      $this->pageUrl->cascade($entity);
    }
  }

  /**
   * Implements hook_menu_link_content_update().
   *
   * A menu link renamed or moved changes the addresses of the page it points
   * at (when moved) and of every page under it: pathauto only re-aliases a
   * page when the page itself is saved.
   */
  #[Hook('menu_link_content_update')]
  public function menuLinkContentUpdate(EntityInterface $link): void {
    $original = $link->getOriginal();
    if (!$original || ($original->label() === $link->label() && $original->get('parent')->value === $link->get('parent')->value)) {
      return;
    }
    $page = $this->pageUrl->linkedEntity($link->getPluginId());
    if ($page) {
      $this->pageUrl->realias($page);
      $this->pageUrl->cascade($page);
    }
  }

  /**
   * Entity builder: the URL field's values onto the page.
   *
   * The slug into the overrides — also where the form has no overrides
   * widget to save it — and the rest onto the page's path.
   */
  public static function buildEntity(string $entityTypeId, ContentEntityInterface $entity, array &$form, FormStateInterface $form_state): void {
    $values = (array) $form_state->getValue([SeoOverrides::FIELD, 0], []);
    if (array_key_exists('slug', $values) && $entity->hasField(SeoOverrides::FIELD)) {
      $slug = trim((string) $values['slug']);
      $entity->set(SeoOverrides::FIELD, ['slug' => $slug === '' ? NULL : $slug] + SeoOverrides::of($entity));
    }
    $url = array_intersect_key($values, array_flip(PageUrl::KEYS));
    \Drupal::service('neo_seo.page_url')->apply($entity, \Drupal::currentUser(), $url);
  }

}
