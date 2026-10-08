<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Plugin\HeadTag;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_seo\Attribute\HeadTag;
use Drupal\neo_seo\Head\HeadTagBase;
use Drupal\neo_seo\Head\HeadValues;
use Drupal\neo_settings\SettingsRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Search engine and platform site-verification codes, on the front page.
 *
 * The verifiers only fetch the home page, so the codes are printed there and
 * nowhere else.
 */
#[HeadTag(
  id: 'verification',
  label: new TranslatableMarkup('Site verification'),
  weight: 100,
)]
class Verification extends HeadTagBase implements ContainerFactoryPluginInterface {

  /**
   * The meta name for each verification key.
   */
  public const NAMES = [
    'google' => 'google-site-verification',
    'bing' => 'msvalidate.01',
    'yandex' => 'yandex-verification',
    'pinterest' => 'p:domain_verify',
    'facebook' => 'facebook-domain-verification',
  ];

  /**
   * The neo_seo settings repository.
   */
  protected SettingsRepository $settings;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->settings = $container->get('neo_seo.settings');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function build(HeadValues $values): array {
    if (!$values->context->isFront) {
      return [];
    }
    $codes = (array) $this->settings->getActive()->getValue('verification');
    $tags = [];
    foreach (static::NAMES as $key => $name) {
      $tags = array_merge($tags, $this->meta($name, trim((string) ($codes[$key] ?? ''))));
    }
    return $tags;
  }

}
