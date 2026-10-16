<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Alchemist;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Template\Attribute;
use Drupal\neo_alchemist\ComponentInstanceInterface;
use Drupal\neo_alchemist\ComponentInterface;
use Drupal\neo_alchemist\Plugin\ComponentValue\ViewsValue;
use Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\neo_seo\ComponentSchema\Evaluator;
use Drupal\neo_seo\Graph\PageContext;
use Drupal\neo_seo\Graph\SchemaIds;
use Psr\Log\LoggerInterface;

/**
 * The schema nodes the Alchemist components on a page declare.
 *
 * A component declares them in its `.component.yml`, under a top-level `seo`
 * key, shaped like the JSON-LD they become:
 *
 * @code
 * seo:
 *   - '@type': Place
 *     '@each': locations as office
 *     name: '{{ office.title }}'
 *     address: '{{ office.address|postal_address }}'
 *     parentOrganization: '{{ graph.organization }}'
 * @endcode
 *
 * The syntax is the Evaluator's. Values are read over resolved props — what
 * the page renders, bindings included — and not at render time, which only
 * reaches a component on a render-cache miss. Only published instances the
 * visitor can see take part.
 *
 * @see \Drupal\neo_seo\ComponentSchema\Evaluator
 */
class ComponentPieceCollector {

  /**
   * What Google needs before it shows a type, and how to fill some of it.
   *
   * `required` must all be present; `one_of` needs at least one. A node that
   * cannot meet them is dropped rather than emitted invalid.
   */
  public const REQUIREMENTS = [
    'VideoObject' => [
      'required' => ['name', 'thumbnailUrl', 'uploadDate'],
      'one_of' => ['contentUrl', 'embedUrl'],
    ],
  ];

  /**
   * Nodes already reported as dropped in this request.
   *
   * @var array<string, true>
   */
  protected array $reported = [];

  /**
   * Constructs the collector.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   * @param \Psr\Log\LoggerInterface $logger
   *   The neo_seo logger.
   * @param \Drupal\neo_seo\ComponentSchema\Evaluator $evaluator
   *   Builds a declaration's nodes.
   * @param \Drupal\neo_seo\Graph\SchemaIds $ids
   *   The graph's ids.
   */
  public function __construct(
    protected ModuleHandlerInterface $moduleHandler,
    protected LoggerInterface $logger,
    protected Evaluator $evaluator,
    protected SchemaIds $ids,
  ) {}

  /**
   * The declared nodes of every published component on the page's entity.
   *
   * @return array<int, array{uuid: string, component: string, type: string, index: int|null, node: array, required: string[]}>
   *   One entry per node, in reading order: the instance it came from, its
   *   type, its row (from 1) when its declaration repeats, the node as
   *   declared, and the properties the declaration requires.
   */
  public function collect(PageContext $context): array {
    $entity = $context->entity;
    if (!$entity instanceof FieldableEntityInterface || !$this->moduleHandler->moduleExists('neo_alchemist')) {
      return [];
    }
    // Resolving a views-bound prop while a view is executing recurses.
    if (class_exists(ViewsValue::class) && ViewsValue::isExecuting()) {
      return [];
    }
    $pieces = [];
    foreach ($entity->getFieldDefinitions() as $name => $definition) {
      if ($definition->getType() !== 'neo_component_tree') {
        continue;
      }
      $item = $entity->get($name)->first();
      if ($item instanceof ComponentTreeItem) {
        $relevant = [];
        $this->declaring($item, ComponentTreeStructure::ROOT_UUID, $relevant);
        $this->walk($item, ComponentTreeStructure::ROOT_UUID, $context, $pieces, $relevant);
      }
    }
    return $pieces;
  }

  /**
   * The nodes of one component on its own, from its examples.
   *
   * For the component audit and `drush neo-seo:component`: what a page
   * placing the component would get.
   *
   * @return array{nodes: array, dropped: array}
   *   As finish() returns them.
   */
  public function preview(ComponentInterface $component, PageContext $context): array {
    $pieces = $this->pieces($component, 'preview', $context);
    return $this->finish($pieces, $context, ['datePublished' => date('c')]);
  }

  /**
   * Gives each node its @id and type fallbacks, and checks it is complete.
   *
   * @param array $pieces
   *   As collect() returns them.
   * @param \Drupal\neo_seo\Graph\PageContext $context
   *   The page.
   * @param array{datePublished?: string|null} $page
   *   What the page itself says, for the fallbacks.
   *
   * @return array{nodes: array, dropped: array<int, array>}
   *   The nodes to print, and the pieces left out, each with its `missing`
   *   properties.
   */
  public function finish(array $pieces, PageContext $context, array $page = []): array {
    $nodes = [];
    $dropped = [];
    $used = [];
    foreach ($pieces as $piece) {
      $node = $piece['node'] + $this->fallbacks($piece['type'], $context, $page);
      $missing = static::missing($piece['type'], $node, $piece['required']);
      if ($missing) {
        $dropped[] = $piece + ['missing' => $missing];
        continue;
      }
      $node['@id'] ??= $this->ids->pageScoped($context, strtolower($piece['type']) . '-' . $piece['uuid'] . ($piece['index'] !== NULL ? '-' . $piece['index'] : ''));
      // Two nodes at one @id would be read as one: a declared id made from
      // a title two rows share takes a number.
      $id = $node['@id'];
      for ($n = 2; isset($used[$id]); $n++) {
        $id = $node['@id'] . '-' . $n;
      }
      $used[$id] = TRUE;
      $node['@id'] = $id;
      // @type and @id first, as every other node prints them.
      $nodes[] = ['@type' => $node['@type'], '@id' => $node['@id']] + $node;
    }
    return ['nodes' => $nodes, 'dropped' => $dropped];
  }

  /**
   * What a node is missing: its type's requirements, then its declaration's.
   *
   * @return string[]
   *   The missing properties; empty when the node is complete.
   */
  public static function missing(string $type, array $values, array $required = []): array {
    $requirements = static::REQUIREMENTS[$type] ?? [];
    $required = array_unique([...($requirements['required'] ?? []), ...$required]);
    $missing = array_values(array_filter($required, static fn(string $property): bool => empty($values[$property])));
    $oneOf = $requirements['one_of'] ?? [];
    if ($oneOf && !array_filter($oneOf, static fn(string $property): bool => !empty($values[$property]))) {
      $missing[] = implode('|', $oneOf);
    }
    return $missing;
  }

  /**
   * Nodes as the page prints them, readable: for previews.
   */
  public static function json(array $nodes): string {
    return (string) json_encode(
      ['@context' => 'https://schema.org', '@graph' => $nodes],
      JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    );
  }

  /**
   * Logs once per request that a declared node was dropped.
   */
  public function reportDropped(PageContext $context, array $piece): void {
    $key = $context->canonicalUrl . '|' . $piece['uuid'] . '|' . $piece['type'] . '|' . $piece['index'];
    if (isset($this->reported[$key])) {
      return;
    }
    $this->reported[$key] = TRUE;
    $this->logger->warning('The @type of component @component on @url was left out of the graph: it has no @missing.', [
      '@type' => $piece['type'] . ($piece['index'] !== NULL ? ' (row ' . $piece['index'] . ')' : ''),
      '@component' => $piece['component'],
      '@url' => $context->canonicalUrl,
      '@missing' => implode(', ', $piece['missing']),
    ]);
  }

  /**
   * Values a type may take from the page when its component has none.
   *
   * A video is published with its page unless it says otherwise, is named
   * for the page when it has no title of its own, and is part of the page.
   */
  protected function fallbacks(string $type, PageContext $context, array $page): array {
    if ($type !== 'VideoObject') {
      return [];
    }
    return array_filter([
      'uploadDate' => $page['datePublished'] ?? NULL,
      'name' => $context->title,
      'isPartOf' => SchemaIds::ref($this->ids->webPage($context)),
      'inLanguage' => $context->langcode,
    ]);
  }

  /**
   * Walks the published children of one parent, depth first.
   */
  protected function walk(ComponentTreeItem $item, string $parentUuid, PageContext $context, array &$pieces, array $relevant): void {
    foreach ($item->getComponents($parentUuid) as $uuid => $instance) {
      // Only what declares nodes, or holds what does, is looked at: checking
      // a component's access resolves its values, which for an image can
      // mean building its derivative — on a page of many components, more
      // time and memory than a request has.
      if (empty($relevant[(string) $uuid])) {
        continue;
      }
      if (!$instance instanceof ComponentInstanceInterface || !$instance->isPublished()) {
        continue;
      }
      $access = $instance->access('view', NULL, TRUE);
      $context->addCacheableDependency($access);
      if (!$access->isAllowed()) {
        continue;
      }
      array_push($pieces, ...$this->pieces($instance, (string) $uuid, $context));
      $context->addCacheableDependency($instance->getCacheableMetadata());
      $this->walk($item, (string) $uuid, $context, $pieces, $relevant);
    }
  }

  /**
   * Marks the components that declare nodes, or hold one that does.
   *
   * From the component definitions alone: nothing is resolved.
   *
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem $item
   *   The tree.
   * @param string $parentUuid
   *   Where to start.
   * @param array<string, bool> $relevant
   *   Collects, by instance uuid, whether it or anything beneath it declares.
   *
   * @return bool
   *   Whether anything beneath the parent declares.
   */
  protected function declaring(ComponentTreeItem $item, string $parentUuid, array &$relevant): bool {
    $any = FALSE;
    foreach ($item->getComponents($parentUuid) as $uuid => $instance) {
      $declares = $instance instanceof ComponentInterface && !empty($instance->getComponentDefinition()['seo']);
      $beneath = $this->declaring($item, (string) $uuid, $relevant);
      $relevant[(string) $uuid] = $declares || $beneath;
      $any = $any || $relevant[(string) $uuid];
    }
    return $any;
  }

  /**
   * The declared nodes of one component.
   */
  protected function pieces(ComponentInterface $component, string $uuid, PageContext $context): array {
    $declarations = $component->getComponentDefinition()['seo'] ?? NULL;
    if (!is_array($declarations) || !$declarations) {
      return [];
    }
    $prop = function (string $name) use ($component): mixed {
      // Only the prop read is resolved: resolving every prop would build
      // render arrays for a whole region's children.
      return $component->getPropShape($name)?->getPropValue(new Attribute());
    };
    $pieces = [];
    foreach ($this->evaluator->evaluate($declarations, \Closure::fromCallable($prop), $context) as $built) {
      $type = $built['node']['@type'] ?? '';
      $type = is_array($type) ? (string) reset($type) : (string) $type;
      // No type, no node: the lint reports it.
      if ($type === '') {
        continue;
      }
      $pieces[] = [
        'uuid' => $uuid,
        'component' => (string) $component->getComponentId(),
        'type' => $type,
        'index' => $built['index'],
        'node' => $built['node'],
        'required' => $built['required'],
      ];
    }
    return $pieces;
  }

}
