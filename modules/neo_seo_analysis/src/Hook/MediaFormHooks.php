<?php

declare(strict_types=1);

namespace Drupal\neo_seo_analysis\Hook;

use Drupal\Component\Utility\Html;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\CloseModalDialogCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\EventSubscriber\MainContentViewSubscriber;
use Drupal\neo_seo_analysis\Ajax\MediaSavedCommand;
use Drupal\neo_seo_analysis\ImageMedia;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * A media item's edit form, opened from the alt text check in a modal.
 *
 * Saving stays in the modal: over AJAX, errors shown in place, and on success
 * the modal closes and the panel is told, so it renders and checks again.
 * Applies only to the edit form opened from the panel (ImageMedia::QUERY) in
 * a dialog; the media form everywhere else is untouched.
 */
class MediaFormHooks {

  /**
   * Constructs the hooks.
   *
   * @param \Drupal\neo_seo_analysis\ImageMedia $imageMedia
   *   Says whether the request came from the panel.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack, for the wrapper format.
   */
  public function __construct(
    protected ImageMedia $imageMedia,
    protected RequestStack $requestStack,
  ) {}

  /**
   * Implements hook_form_BASE_FORM_ID_alter() for media_form.
   */
  #[Hook('form_media_form_alter')]
  public function formMediaFormAlter(array &$form, FormStateInterface $form_state): void {
    $form_object = $form_state->getFormObject();
    if (!$form_object instanceof EntityFormInterface || $form_object->getOperation() !== 'edit' || !$this->imageMedia->fromPanel()) {
      return;
    }
    $wrapper = $this->requestStack->getCurrentRequest()?->query->get(MainContentViewSubscriber::WRAPPER_FORMAT);
    if (!in_array($wrapper, ['drupal_modal', 'drupal_ajax'], TRUE) || !isset($form['actions']['submit'])) {
      return;
    }
    // A stable id, so the AJAX response replaces the same form (#2897377).
    $form['#id'] = Html::getId($form_state->getBuildInfo()['form_id']);
    $form['actions']['submit']['#ajax'] = [
      'callback' => [static::class, 'ajaxSave'],
      'disable-refocus' => TRUE,
    ];
    foreach ($form['actions'] as $key => &$action) {
      if (is_array($action) && !str_starts_with((string) $key, '#') && $key !== 'submit') {
        $action['#access'] = FALSE;
      }
    }
    unset($action);
  }

  /**
   * The AJAX save: the form again with its errors, or closed and announced.
   */
  public static function ajaxSave(array &$form, FormStateInterface $form_state): AjaxResponse {
    $response = new AjaxResponse();
    if ($form_state->hasAnyErrors()) {
      $form['status_messages'] = ['#type' => 'status_messages', '#weight' => -1000];
      $response->addCommand(new ReplaceCommand('[data-drupal-selector="' . $form['#attributes']['data-drupal-selector'] . '"]', $form));
      return $response;
    }
    // The media form's "updated" message would show on the next page; the
    // panel's own re-check says the same.
    \Drupal::messenger()->deleteAll();
    $media = $form_state->getFormObject() instanceof EntityFormInterface
      ? $form_state->getFormObject()->getEntity()
      : NULL;
    $response->addCommand(new CloseModalDialogCommand());
    $response->addCommand(new MediaSavedCommand((int) $media?->id()));
    return $response;
  }

}
