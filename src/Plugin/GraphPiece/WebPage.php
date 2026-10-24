<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Plugin\GraphPiece;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_seo\Attribute\GraphPiece;
use Drupal\neo_seo\Graph\BreadcrumbTrail;
use Drupal\neo_seo\Graph\GraphPieceBase;
use Drupal\neo_seo\Graph\ListedItems;
use Drupal\neo_seo\Graph\PageContext;
use Drupal\neo_seo\Graph\People;
use Drupal\neo_seo\Graph\SchemaIds;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The page itself, part of the WebSite. Its id is the canonical URL.
 */
#[GraphPiece(
  id: 'webpage',
  label: new TranslatableMarkup('Web page'),
  weight: -80,
)]
class WebPage extends GraphPieceBase {

  /**
   * The breadcrumb trail.
   */
  protected BreadcrumbTrail $breadcrumbTrail;

  /**
   * The people service, for a profile page's main entity.
   */
  protected People $people;

  /**
   * What a collection page lists, its main entity.
   */
  protected ListedItems $listedItems;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->breadcrumbTrail = $container->get('neo_seo.breadcrumb_trail');
    $instance->people = $container->get('neo_seo.people');
    $instance->listedItems = $container->get('neo_seo.listed_items');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function generate(PageContext $context): array {
    $values = $this->headValues->resolve($context);
    $image = $values->image();
    $profile = $values->pageType === 'ProfilePage' ? $this->people->profile($context) : NULL;
    $mainEntity = match (TRUE) {
      $profile !== NULL => SchemaIds::ref($this->people->id($context, $profile)),
      (bool) $this->listedItems->items($context) => SchemaIds::ref($this->ids->itemList($context)),
      default => NULL,
    };
    return [
      [
        '@type' => $values->pageType === 'WebPage' ? 'WebPage' : ['WebPage', $values->pageType],
        '@id' => $this->ids->webPage($context),
        'url' => $context->canonicalUrl,
        'name' => $values->get('title') ?: $context->title,
        'description' => $values->get('description'),
        'isPartOf' => SchemaIds::ref($this->ids->website($context)),
        'about' => $context->isFront ? SchemaIds::ref($this->ids->siteIdentity($context)) : NULL,
        'mainEntity' => $mainEntity,
        'primaryImageOfPage' => $image ? SchemaIds::ref($this->ids->primaryImage($context)) : NULL,
        'image' => $image ? SchemaIds::ref($this->ids->primaryImage($context)) : NULL,
        'thumbnailUrl' => $image['url'] ?? NULL,
        'datePublished' => $values->datePublished,
        'dateModified' => $values->dateModified,
        'breadcrumb' => $this->breadcrumbTrail->items($context) ? SchemaIds::ref($this->ids->breadcrumb($context)) : NULL,
        'inLanguage' => $context->langcode,
      ],
    ];
  }

}
