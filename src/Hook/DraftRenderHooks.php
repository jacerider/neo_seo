<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Hook;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Entity\ContentEntityFormInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Render\Element;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\neo_seo\Ajax\DraftRenderCommand;
use Drupal\neo_seo\SeoOverrides;

/**
 * The edit form's draft render: the page as the form's values would make it.
 */
class DraftRenderHooks {

  use StringTranslationTrait;

  /**
   * The name of the hidden button the SEO panel presses.
   */
  public const BUTTON = 'neo_seo_draft_render';

  /**
   * Implements hook_form_alter().
   *
   * Content entity forms with SEO overrides get a hidden button. Pressing it
   * over AJAX builds the entity from the form as it stands — without saving,
   * and whether or not the form would validate — and answers with its draft
   * render.
   */
  #[Hook('form_alter')]
  public function formAlter(array &$form, FormStateInterface $form_state, string $form_id): void {
    $formObject = $form_state->getFormObject();
    if (!$formObject instanceof ContentEntityFormInterface || !isset($form[SeoOverrides::FIELD])) {
      return;
    }
    $operation = $formObject->getOperation();
    if (!in_array($operation, ['default', 'edit', 'add'], TRUE)) {
      return;
    }
    // Every field keeps its values; nothing else is validated. The list is
    // completed in afterBuild(), once every element knows its #parents.
    $sections = [];
    foreach ($formObject->getEntity()->getFieldDefinitions() as $name => $definition) {
      if (isset($form[$name])) {
        $sections[] = [$name];
      }
    }
    $form['#after_build'][] = [static::class, 'afterBuild'];
    $form['actions'][static::BUTTON] = [
      '#type' => 'submit',
      '#value' => $this->t('Render draft'),
      '#name' => static::BUTTON,
      '#limit_validation_errors' => $sections,
      '#submit' => [],
      '#ajax' => [
        'callback' => [static::class, 'ajaxRender'],
        'progress' => ['type' => 'none'],
      ],
      '#attributes' => [
        'class' => ['neo-seo-draft-render', 'visually-hidden'],
        'tabindex' => '-1',
        'aria-hidden' => 'true',
      ],
      '#weight' => 1000,
    ];
  }

  /**
   * Form #after_build: keep the value of every input when drafting.
   *
   * A section in #limit_validation_errors keeps only the values under it, and
   * an element's value lands at its #parents, which need not start with a
   * field name: the term form's parent select sits in a details yet stores at
   * ['parent'], and TermForm::buildEntity() reads it from there. So once the
   * form is built, every input's top-level parent becomes a section. The
   * triggering element is a copy taken during the build, so it is updated in
   * the form state, which is what validation reads.
   */
  public static function afterBuild(array $form, FormStateInterface $form_state): array {
    $trigger = $form_state->getTriggeringElement();
    if (($trigger['#name'] ?? NULL) !== static::BUTTON) {
      return $form;
    }
    $sections = $trigger['#limit_validation_errors'] ?? [];
    $keys = [];
    static::collectTopParents($form, $keys);
    foreach (array_keys($keys) as $key) {
      $sections[] = [$key];
    }
    $trigger['#limit_validation_errors'] = array_values(array_unique($sections, SORT_REGULAR));
    $form_state->setTriggeringElement($trigger);
    return $form;
  }

  /**
   * Collects the first #parents key of every input in a form tree.
   *
   * @param array $element
   *   The element.
   * @param array<string, true> $keys
   *   The keys found so far.
   */
  protected static function collectTopParents(array $element, array &$keys): void {
    if (!empty($element['#input']) && !empty($element['#parents'])) {
      $keys[(string) reset($element['#parents'])] = TRUE;
    }
    foreach (Element::children($element) as $child) {
      if (is_array($element[$child])) {
        static::collectTopParents($element[$child], $keys);
      }
    }
  }

  /**
   * AJAX callback: the draft render of the form's entity.
   */
  public static function ajaxRender(array &$form, FormStateInterface $form_state): AjaxResponse {
    // Errors from a half-filled form are not this button's business.
    $form_state->clearErrors();
    \Drupal::messenger()->deleteByType('error');
    /** @var \Drupal\Core\Entity\ContentEntityFormInterface $formObject */
    $formObject = $form_state->getFormObject();
    $entity = $formObject->buildEntity($form, $form_state);
    $response = new AjaxResponse();
    if ($entity instanceof ContentEntityInterface) {
      $response->addCommand(new DraftRenderCommand(\Drupal::service('neo_seo.draft_renderer')->render($entity)));
    }
    return $response;
  }

  /**
   * Implements hook_neo_token_title_alter().
   *
   * [neo:title] reads the current route's title, which while drafting is the
   * edit form's ("Edit …"). A draft is titled by its own label.
   */
  #[Hook('neo_token_title_alter')]
  public function neoTokenTitleAlter(&$title, array $params, ?ContentEntityInterface $entity = NULL): void {
    if (!$title && $entity && !empty($entity->in_preview)) {
      $title = (string) $entity->label();
    }
  }

}
