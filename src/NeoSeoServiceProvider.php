<?php

declare(strict_types=1);

namespace Drupal\neo_seo;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Drupal\neo_seo\Alchemist\SchemaAuditCheck;
use Drupal\neo_seo\Alchemist\SchemaLinter;
use Drupal\neo_seo\MediaLibrary\MediaPickerOpener;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Registers what implements another module's interface, while it is enabled.
 *
 * - media_library: the media picker's opener;
 * - neo_alchemist: the `seo` declaration's lint rule and audit check.
 *
 * Neither is a dependency, so neither can be a plain service.
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
    if (isset($modules['neo_alchemist'])) {
      $container->register('neo_seo.component_schema_lint', SchemaLinter::class)
        ->addArgument(new Reference('neo_seo.component_schema_linter'))
        ->addTag('neo_alchemist.component_linter');
      $container->register('neo_seo.component_schema_audit', SchemaAuditCheck::class)
        ->addArgument(new Reference('neo_seo.component_pieces'))
        ->addArgument(new Reference('neo_seo.page_context_factory'))
        ->addArgument(new Reference('renderer'))
        ->addTag('neo_alchemist.component_audit_check');
    }
  }

}
