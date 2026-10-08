<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Settings;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\neo_seo\SeoOverridesStorage;
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
    ['override_entity_types'],
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
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The SEO overrides field storage manager.
   */
  protected SeoOverridesStorage $overrides;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->entityRepository = $container->get('entity.repository');
    $instance->moduleHandler = $container->get('module_handler');
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->overrides = $container->get('neo_seo.overrides_storage');
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
    $options = [];
    foreach ($this->entityTypeManager->getDefinitions() as $id => $definition) {
      if ($definition instanceof ContentEntityTypeInterface && $definition->hasLinkTemplate('canonical')) {
        $options[$id] = $definition->getLabel();
      }
    }
    asort($options);
    $form['override_entity_types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Per-page SEO'),
      '#description' => $this->t('Content that editors can give its own search title, description, share image, schema types and focus keyphrase.'),
      '#options' => $options,
      '#default_value' => $this->getValue('override_entity_types') ?: [],
    ];

    $styles = [];
    if ($this->entityTypeManager->hasDefinition('image_style')) {
      foreach ($this->entityTypeManager->getStorage('image_style')->loadMultiple() as $id => $style) {
        $styles[$id] = $style->label();
      }
    }
    $form['share_image_style'] = [
      '#type' => 'select',
      '#title' => $this->t('Share image style'),
      '#description' => $this->t('Images shown when a page is shared, and the graph’s primary images, are served through this style. 1200×630 suits every network.'),
      '#options' => $styles,
      '#empty_option' => $this->t('- Original image -'),
      '#default_value' => $this->getValue('share_image_style'),
    ];

    $verification = $this->getValue('verification') ?: [];
    $form['verification'] = [
      '#type' => 'details',
      '#title' => $this->t('Site verification'),
      '#description' => $this->t('The code from each service’s verification meta tag — the content value only. Printed on the front page.'),
      '#open' => !empty(array_filter($verification)),
      '#tree' => TRUE,
    ];
    $services = [
      'google' => $this->t('Google Search Console'),
      'bing' => $this->t('Bing Webmaster Tools'),
      'yandex' => $this->t('Yandex Webmaster'),
      'pinterest' => $this->t('Pinterest'),
      'facebook' => $this->t('Facebook domain verification'),
    ];
    foreach ($services as $key => $label) {
      $form['verification'][$key] = [
        '#type' => 'textfield',
        '#title' => $label,
        '#default_value' => $verification[$key] ?? '',
      ];
    }
    $form['head_alongside_metatag'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Print head tags while metatag is enabled'),
      '#description' => $this->t('neo_seo stays out of the head while the metatag module is enabled, so a page never gets two titles. Turn this on only to compare the two before switching over.'),
      '#default_value' => (bool) $this->getValue('head_alongside_metatag'),
      '#access' => $this->moduleHandler->moduleExists('metatag'),
    ];
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
    $form_state->setValue('head_alongside_metatag', (bool) $form_state->getValue('head_alongside_metatag'));

    // Removing per-page SEO from an entity type drops its column, and with it
    // every value editors set. Refuse while there is any.
    $enabled = array_values(array_filter((array) $form_state->getValue('override_entity_types')));
    $form_state->setValue('override_entity_types', $enabled);
    foreach (array_diff((array) $this->getValue('override_entity_types'), $enabled) as $removed) {
      if ($this->overrides->hasData($removed)) {
        $form_state->setErrorByName('override_entity_types', $this->t('%type has SEO overrides stored; they would be deleted. Clear them first.', [
          '%type' => $this->entityTypeManager->getDefinition($removed)->getLabel(),
        ]));
      }
    }
  }

}
