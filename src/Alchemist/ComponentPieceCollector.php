<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Alchemist;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Template\Attribute;
use Drupal\neo_alchemist\ComponentInstanceInterface;
use Drupal\neo_alchemist\Plugin\ComponentValue\ViewsValue;
use Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\neo_seo\Graph\PageContext;
use Psr\Log\LoggerInterface;

/**
 * Collects the schema pieces Alchemist components declare.
 *
 * A component opts in from its `.component.yml`:
 *
 * @code
 * thirdPartySettings:
 *   neo_seo:
 *     pieces:
 *       - type: VideoObject
 *         properties:
 *           name: title
 *           contentUrl: video.src
 *           thumbnailUrl: video.poster
 * @endcode
 *
 * Each property names a prop, then keys into its value. The tree is walked
 * over resolved values — what the page renders, bindings included — and not
 * at render time, which only reaches a component on a render-cache miss.
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
   * Instances already reported as dropped in this request.
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
   */
  public function __construct(
    protected ModuleHandlerInterface $moduleHandler,
    protected LoggerInterface $logger,
  ) {}

  /**
   * The declared pieces of every published component on the page's entity.
   *
   * @return array<int, array{uuid: string, component: string, type: string, values: array<string, mixed>}>
   *   One entry per declared piece per component instance, in reading order.
   *   Values are as resolved; empty ones are left out.
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
        $this->walk($item, ComponentTreeStructure::ROOT_UUID, $context, $pieces);
      }
    }
    return $pieces;
  }

  /**
   * Whether a resolved piece has what its type requires.
   *
   * @return string[]
   *   The missing properties; empty when the piece is complete.
   */
  public static function missing(string $type, array $values): array {
    $requirements = static::REQUIREMENTS[$type] ?? [];
    $missing = array_values(array_filter($requirements['required'] ?? [], static fn(string $property): bool => empty($values[$property])));
    $oneOf = $requirements['one_of'] ?? [];
    if ($oneOf && !array_filter($oneOf, static fn(string $property): bool => !empty($values[$property]))) {
      $missing[] = implode('|', $oneOf);
    }
    return $missing;
  }

  /**
   * Logs once per request that a component's piece was dropped.
   */
  public function reportDropped(PageContext $context, array $piece, array $missing): void {
    $key = $context->canonicalUrl . '|' . $piece['uuid'] . '|' . $piece['type'];
    if (isset($this->reported[$key])) {
      return;
    }
    $this->reported[$key] = TRUE;
    $this->logger->warning('The @type of component @component on @url was left out of the graph: it has no @missing.', [
      '@type' => $piece['type'],
      '@component' => $piece['component'],
      '@url' => $context->canonicalUrl,
      '@missing' => implode(', ', $missing),
    ]);
  }

  /**
   * Walks the published children of one parent, depth first.
   */
  protected function walk(ComponentTreeItem $item, string $parentUuid, PageContext $context, array &$pieces): void {
    foreach ($item->getComponents($parentUuid) as $uuid => $instance) {
      if (!$instance instanceof ComponentInstanceInterface || !$instance->isPublished()) {
        continue;
      }
      $access = $instance->access('view', NULL, TRUE);
      $context->addCacheableDependency($access);
      if (!$access->isAllowed()) {
        continue;
      }
      $declared = $instance->getComponentDefinition()['thirdPartySettings']['neo_seo']['pieces'] ?? [];
      foreach ((array) $declared as $declaration) {
        if (empty($declaration['type']) || empty($declaration['properties'])) {
          continue;
        }
        $values = [];
        foreach ((array) $declaration['properties'] as $property => $path) {
          $value = $this->value($instance, (string) $path);
          if ($value !== NULL && $value !== '' && $value !== []) {
            $values[$property] = $value;
          }
        }
        $pieces[] = [
          'uuid' => (string) $uuid,
          'component' => (string) $instance->getComponentId(),
          'type' => (string) $declaration['type'],
          'values' => $values,
        ];
      }
      $context->addCacheableDependency($instance->getCacheableMetadata());
      $this->walk($item, (string) $uuid, $context, $pieces);
    }
  }

  /**
   * A resolved prop value, keyed into by the rest of a dotted path.
   *
   * Only the named prop is resolved: resolving every prop would build render
   * arrays for a whole region's children.
   */
  protected function value(ComponentInstanceInterface $instance, string $path): mixed {
    $keys = explode('.', $path);
    $shape = $instance->getPropShape((string) array_shift($keys));
    if (!$shape) {
      return NULL;
    }
    $value = $shape->getPropValue(new Attribute());
    foreach ($keys as $key) {
      if (!is_array($value) || !array_key_exists($key, $value)) {
        return NULL;
      }
      $value = $value[$key];
    }
    if (is_object($value) && method_exists($value, '__toString')) {
      $value = (string) $value;
    }
    return is_scalar($value) ? trim((string) $value) : $value;
  }

}
