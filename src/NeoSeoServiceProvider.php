<?php

declare(strict_types=1);

namespace Drupal\neo_seo;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Drupal\neo_seo\MediaLibrary\MediaPickerOpener;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Registers the media library opener while media_library is enabled.
 *
 * The opener implements media_library's interface, so it cannot be a plain
 * service of a module that does not depend on media_library.
 */
class NeoSeoServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    $modules = $container->getParameter('container.modules');
    if (isset($modules['media_library'])) {
      $container->register('neo_seo.media_library_opener', MediaPickerOpener::class)
        ->addArgument(new Reference('entity_type.manager'))
        ->addTag('media_library.opener');
    }
  }

}
