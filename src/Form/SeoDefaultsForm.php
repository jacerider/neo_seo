<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Form;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\neo_seo\Entity\SeoDefaultsInterface;
use Drupal\neo_seo\Head\HeadTagPluginManager;
use Drupal\token\TokenEntityMapperInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Edits a layer of default head-tag templates.
 */
class SeoDefaultsForm extends EntityForm {

  /**
   * The head tag plugin manager.
   *
   * @var \Drupal\neo_seo\Head\HeadTagPluginManager
   */
  protected $headTagManager;

  /**
   * The bundle info service.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   */
  protected $bundleInfo;

  /**
   * The token entity mapper.
   *
   * @var \Drupal\token\TokenEntityMapperInterface
   */
  protected $tokenEntityMapper;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->headTagManager = $container->get(HeadTagPluginManager::class);
    $instance->bundleInfo = $container->get(EntityTypeBundleInfoInterface::class);
    $instance->tokenEntityMapper = $container->get(TokenEntityMapperInterface::class);
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);
    /** @var \Drupal\neo_seo\Entity\SeoDefaultsInterface $defaults */
    $defaults = $this->entity;

    if ($defaults->isNew()) {
      $form['id'] = [
        '#type' => 'select',
        '#title' => $this->t('Applies to'),
        '#options' => $this->targetOptions(),
        '#required' => TRUE,
        '#description' => $this->t('Only content with pages of its own is listed, and only where no set exists yet.'),
      ];
    }
    else {
      $form['applies_to'] = [
        '#type' => 'item',
        '#title' => $this->t('Applies to'),
        '#markup' => $defaults->label(),
      ];
    }

    $form['values'] = [
      '#type' => 'container',
      '#tree' => TRUE,
    ];
    $values = $defaults->getValues();
    foreach ($this->headTagManager->getFields() as $field => $label) {
      $form['values'][$field] = [
        '#type' => 'textfield',
        '#title' => $label,
        '#default_value' => $values[$field] ?? '',
        '#maxlength' => 1024,
        '#description' => $defaults->id() === 'global' ? NULL : $this->t('Leave empty to keep the value from the sets below this one.'),
      ];
    }
    $form['values']['tokens'] = [
      '#theme' => 'token_tree_link',
      '#token_types' => $this->tokenTypes($defaults),
      '#global_types' => TRUE,
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
    if ($this->entity->isNew()) {
      $id = (string) $form_state->getValue('id');
      if ($this->entityTypeManager->getStorage('neo_seo_defaults')->load($id)) {
        $form_state->setErrorByName('id', $this->t('A set for this already exists.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function copyFormValuesToEntity($entity, array $form, FormStateInterface $form_state) {
    /** @var \Drupal\neo_seo\Entity\SeoDefaultsInterface $entity */
    if ($entity->isNew()) {
      $id = (string) $form_state->getValue('id');
      $entity->set('id', $id);
      $entity->set('label', $this->labelFor($id));
    }
    $values = (array) $form_state->getValue('values');
    unset($values['tokens']);
    $entity->setValues(array_map(static fn($value): string => trim((string) $value), $values));
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $result = parent::save($form, $form_state);
    $this->messenger()->addStatus($this->t('Saved the %label defaults.', ['%label' => $this->entity->label()]));
    $form_state->setRedirectUrl($this->entity->toUrl('collection'));
    return $result;
  }

  /**
   * Everything a new set could apply to that has no set yet.
   *
   * @return array<string, string|array<string, string>>
   *   Options, grouped by entity type.
   */
  protected function targetOptions(): array {
    $existing = array_flip(array_keys($this->entityTypeManager->getStorage('neo_seo_defaults')->loadMultiple()));
    $options = [];
    foreach (['front', '403', '404'] as $special) {
      if (!isset($existing[$special])) {
        $options[(string) $this->t('Special pages')][$special] = $this->labelFor($special);
      }
    }
    foreach ($this->entityTypeManager->getDefinitions() as $entityTypeId => $entityType) {
      if (!$entityType instanceof ContentEntityTypeInterface || !$entityType->hasLinkTemplate('canonical')) {
        continue;
      }
      $group = (string) $entityType->getLabel();
      if (!isset($existing[$entityTypeId])) {
        $options[$group][$entityTypeId] = $this->t('All @type', ['@type' => $entityType->getPluralLabel()]);
      }
      if ($entityType->getBundleEntityType()) {
        foreach ($this->bundleInfo->getBundleInfo($entityTypeId) as $bundle => $info) {
          if (!isset($existing[$entityTypeId . '__' . $bundle])) {
            $options[$group][$entityTypeId . '__' . $bundle] = (string) $info['label'];
          }
        }
      }
    }
    return $options;
  }

  /**
   * A readable label for a set id.
   */
  protected function labelFor(string $id): string {
    $special = [
      'global' => $this->t('Global'),
      'front' => $this->t('Front page'),
      '403' => $this->t('403 access denied'),
      '404' => $this->t('404 page not found'),
    ];
    if (isset($special[$id])) {
      return (string) $special[$id];
    }
    [$entityTypeId, $bundle] = array_pad(explode('__', $id, 2), 2, NULL);
    $entityType = $this->entityTypeManager->getDefinition($entityTypeId, FALSE);
    if (!$entityType) {
      return $id;
    }
    if (!$bundle) {
      return (string) $entityType->getLabel();
    }
    $bundles = $this->bundleInfo->getBundleInfo($entityTypeId);
    return $entityType->getLabel() . ': ' . ($bundles[$bundle]['label'] ?? $bundle);
  }

  /**
   * The token types a set's templates can use.
   *
   * @return string[]
   *   Token types.
   */
  protected function tokenTypes(SeoDefaultsInterface $defaults): array {
    $id = (string) $defaults->id();
    if ($defaults->isNew() || in_array($id, SeoDefaultsInterface::SPECIAL, TRUE)) {
      return ['neo'];
    }
    [$entityTypeId] = explode('__', $id, 2);
    return ['neo', $this->tokenEntityMapper->getTokenTypeForEntityType($entityTypeId, TRUE)];
  }

}
