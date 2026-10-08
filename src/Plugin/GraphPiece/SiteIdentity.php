<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Plugin\GraphPiece;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_seo\Attribute\GraphPiece;
use Drupal\neo_seo\Graph\GraphPieceBase;
use Drupal\neo_seo\Graph\PageContext;
use Drupal\neo_seo\Graph\SchemaIds;
use Drupal\neo_seo\SiteIdentity\SiteIdentityResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * What the site represents: the node everything else is published by.
 *
 * An Organization (or one of its types) with its logo, or a Person with their
 * image. Either way it sits at `{site}#organization`, so nothing downstream
 * cares which.
 */
#[GraphPiece(
  id: 'site_identity',
  label: new TranslatableMarkup('Site identity'),
  weight: -100,
)]
class SiteIdentity extends GraphPieceBase {

  /**
   * The site identity resolver.
   */
  protected SiteIdentityResolver $resolver;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->resolver = $container->get('neo_seo.site_identity');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function generate(PageContext $context): array {
    $identity = $this->resolver->resolve($context);
    $id = $this->ids->siteIdentity($context);
    $isPerson = $identity['type'] === 'Person';

    $node = [
      '@type' => $identity['type'],
      '@id' => $id,
      'name' => $identity['name'],
      'url' => $context->siteUrl,
      'description' => $identity['description'],
      'sameAs' => $identity['same_as'],
      'telephone' => $identity['telephone'],
      'email' => $identity['email'],
      'address' => $identity['address'] ? [
        '@type' => 'PostalAddress',
        'streetAddress' => $identity['address']['street'] ?? NULL,
        'addressLocality' => $identity['address']['locality'] ?? NULL,
        'addressRegion' => $identity['address']['region'] ?? NULL,
        'postalCode' => $identity['address']['postal_code'] ?? NULL,
        'addressCountry' => $identity['address']['country'] ?? NULL,
      ] : NULL,
    ];

    $nodes = [];
    if ($logo = $identity['logo']) {
      $logoId = $this->ids->logo($context);
      // An organization has a logo; a person has an image. Both point at the
      // same ImageObject, as Yoast does, so the image is described once.
      $node[$isPerson ? 'image' : 'logo'] = SchemaIds::ref($logoId);
      if (!$isPerson) {
        $node['image'] = SchemaIds::ref($logoId);
      }
      $nodes[] = [
        '@type' => 'ImageObject',
        '@id' => $logoId,
        'url' => $logo['url'],
        'contentUrl' => $logo['url'],
        'width' => $logo['width'],
        'height' => $logo['height'],
        'caption' => $logo['caption'],
        'inLanguage' => $context->langcode,
      ];
    }
    array_unshift($nodes, $node);
    return $nodes;
  }

}
