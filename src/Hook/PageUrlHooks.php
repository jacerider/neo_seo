<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Hook;

use Drupal\Core\Entity\ContentEntityFormInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\neo_seo\PageUrl;
use Drupal\neo_seo\SeoOverrides;

/**
 * The page URL in the edit form: neo_seo's controls in place of core's.
 */
class PageUrlHooks {

  /**
   * Constructs the hooks.
   *
   * @param \Drupal\neo_seo\PageUrl $pageUrl
   *   The page URL service.
   */
  public function __construct(
    protected PageUrl $pageUrl,
  ) {}

  /**
   * Implements hook_form_alter().
   *
   * Where neo_seo manages the page's URL and the editor may use the SEO
   * overrides, core's path widget is hidden: the overrides' URL controls are
   * the one place the URL is changed. Their values are applied to the path
   * as the entity is built, so the draft render sees them too. An editor
   * without the overrides keeps core's widget.
   */
  #[Hook('form_alter')]
  public function formAlter(array &$form, FormStateInterface $form_state, string $form_id): void {
    $formObject = $form_state->getFormObject();
    if (!$formObject instanceof ContentEntityFormInterface || ($form[SeoOverrides::FIELD]['#access'] ?? FALSE) === FALSE) {
      return;
    }
    $entity = $formObject->getEntity();
    if (!$entity instanceof ContentEntityInterface || !$this->pageUrl->manages($entity)) {
      return;
    }
    if (isset($form['path'])) {
      $form['path']['#access'] = FALSE;
    }
    $form['#entity_builders'][] = [static::class, 'buildEntity'];
  }

  /**
   * Entity builder: the URL controls' values onto the page's path.
   */
  public static function buildEntity(string $entityTypeId, ContentEntityInterface $entity, array &$form, FormStateInterface $form_state): void {
    $values = (array) $form_state->getValue([SeoOverrides::FIELD, 0], []);
    $url = array_intersect_key($values, array_flip(PageUrl::KEYS));
    \Drupal::service('neo_seo.page_url')->apply($entity, \Drupal::currentUser(), $url);
  }

}
