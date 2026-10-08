<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Settings;

use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\neo_seo\SiteIdentity\OrganizationTypes;
use Drupal\neo_settings\Plugin\SettingsBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Neo SEO settings: what the site represents.
 *
 * @Settings(
 *   id = "neo_seo",
 *   label = @Translation("SEO"),
 *   config_name = "neo_seo.settings",
 *   menu_title = @Translation("SEO"),
 *   route = "/admin/config/neo/seo",
 *   admin_permission = "administer neo_seo",
 *   variation_allow = false,
 * )
 */
class SeoSettings extends SettingsBase {

  /**
   * Lists that must replace, never merge, when saved.
   *
   * @var array
   */
  protected $strictParents = [
    ['identity', 'same_as'],
  ];

  /**
   * The entity repository, to turn the stored logo uuid back into media.
   */
  protected EntityRepositoryInterface $entityRepository;

  /**
   * The module handler.
   */
  protected ModuleHandlerInterface $moduleHandler;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->entityRepository = $container->get('entity.repository');
    $instance->moduleHandler = $container->get('module_handler');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function buildForm(array $form, FormStateInterface $form_state) {
    $identity = $this->getValue('identity') ?: [];

    $form['identity'] = [
      '#type' => 'details',
      '#title' => $this->t('Site identity'),
      '#description' => $this->t('What this site represents. Every page’s structured data is published by it. Text fields accept tokens.'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];
    $form['identity']['type'] = [
      '#type' => 'radios',
      '#title' => $this->t('This site represents'),
      '#options' => [
        'organization' => $this->t('An organization'),
        'person' => $this->t('A person'),
      ],
      '#default_value' => $identity['type'] ?? 'organization',
    ];
    $form['identity']['organization_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Organization type'),
      '#options' => OrganizationTypes::options(),
      '#default_value' => $identity['organization_type'] ?? 'Organization',
      '#states' => [
        'visible' => [':input[name$="[identity][type]"]' => ['value' => 'organization']],
      ],
    ];
    $form['identity']['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#default_value' => $identity['name'] ?? '',
      '#required' => TRUE,
    ];
    $form['identity']['description'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Description'),
      '#default_value' => $identity['description'] ?? '',
      '#maxlength' => 512,
    ];
    $logo = !empty($identity['logo']) ? $this->entityRepository->loadEntityByUuid('media', $identity['logo']) : NULL;
    $form['identity']['logo'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Logo or image'),
      '#description' => $this->t('An image media item. Google shows an organization’s logo in search results; use a square or wide mark at least 112px across.'),
      '#target_type' => 'media',
      '#default_value' => $logo,
    ];
    $form['identity']['same_as'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Social profiles'),
      '#description' => $this->t('One URL per line: LinkedIn, Facebook, Instagram, YouTube, X, Wikipedia …'),
      '#default_value' => implode("\n", $identity['same_as'] ?? []),
      '#rows' => 4,
    ];
    $form['identity']['telephone'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Telephone'),
      '#default_value' => $identity['telephone'] ?? '',
    ];
    $form['identity']['email'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Email'),
      '#default_value' => $identity['email'] ?? '',
    ];
    $form['identity']['address'] = [
      '#type' => 'details',
      '#title' => $this->t('Postal address'),
      '#open' => !empty(array_filter($identity['address'] ?? [])),
    ];
    $labels = [
      'street' => $this->t('Street address'),
      'locality' => $this->t('City'),
      'region' => $this->t('State or region'),
      'postal_code' => $this->t('Postal code'),
      'country' => $this->t('Country'),
    ];
    foreach ($labels as $key => $label) {
      $form['identity']['address'][$key] = [
        '#type' => 'textfield',
        '#title' => $label,
        '#default_value' => $identity['address'][$key] ?? '',
      ];
    }
    if ($this->moduleHandler->moduleExists('token')) {
      $form['identity']['tokens'] = [
        '#theme' => 'token_tree_link',
        '#token_types' => ['site'],
      ];
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function validateForm(array $form, FormStateInterface $form_state) {
    $parents = ['identity', 'same_as'];
    $lines = preg_split('/\R/', (string) $form_state->getValue($parents));
    $form_state->setValue($parents, array_values(array_filter(array_map('trim', $lines))));

    $mediaId = $form_state->getValue(['identity', 'logo']);
    $media = $mediaId ? $this->entityRepository->getActive('media', $mediaId) : NULL;
    $form_state->setValue(['identity', 'logo'], $media ? $media->uuid() : '');
    $form_state->unsetValue(['identity', 'tokens']);
  }

}
