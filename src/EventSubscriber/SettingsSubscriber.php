<?php

declare(strict_types=1);

namespace Drupal\neo_seo\EventSubscriber;

use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigEvents;
use Drupal\neo_seo\SeoOverridesStorage;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Keeps the SEO overrides field storage in step with the settings.
 */
class SettingsSubscriber implements EventSubscriberInterface {

  /**
   * Constructs the subscriber.
   *
   * @param \Drupal\neo_seo\SeoOverridesStorage $overridesStorage
   *   The overrides storage manager.
   */
  public function __construct(
    protected SeoOverridesStorage $overridesStorage,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [ConfigEvents::SAVE => 'onSave'];
  }

  /**
   * Installs or removes the field when the enabled entity types change.
   */
  public function onSave(ConfigCrudEvent $event): void {
    $config = $event->getConfig();
    if ($config->getName() !== 'neo_seo.settings' || !$event->isChanged('override_entity_types')) {
      return;
    }
    $this->overridesStorage->sync((array) $config->get('override_entity_types'));
  }

}
