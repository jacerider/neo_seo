<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Alchemist;

use Drupal\Core\Render\RenderContext;
use Drupal\Core\Render\RendererInterface;
use Drupal\neo_alchemist\Audit\ComponentAuditCheckInterface;
use Drupal\neo_alchemist\Audit\ComponentAuditContext;
use Drupal\neo_seo\Graph\PageContextFactory;

/**
 * What a component's `seo` declaration produces, in the component audit.
 *
 * Built from the component's examples (or the values being edited in its
 * preview) on an example page. Each node the page would leave out is an
 * issue; the nodes it would print are the audit's "Schema (JSON-LD)" report.
 * Once, on the examples render: the declaration reads the component, not its
 * markup.
 *
 * Registered only while neo_alchemist is enabled (NeoSeoServiceProvider).
 */
class SchemaAuditCheck implements ComponentAuditCheckInterface {

  /**
   * Constructs the check.
   *
   * @param \Drupal\neo_seo\Alchemist\ComponentPieceCollector $collector
   *   Builds the declared nodes.
   * @param \Drupal\neo_seo\Graph\PageContextFactory $contexts
   *   The example page.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer: resolving a prop may render.
   */
  public function __construct(
    protected ComponentPieceCollector $collector,
    protected PageContextFactory $contexts,
    protected RendererInterface $renderer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'schema';
  }

  /**
   * {@inheritdoc}
   */
  public function check(\DOMDocument $document, ComponentAuditContext $context): array {
    if ($context->variant !== 'examples' || empty($context->entity->getComponentDefinition()['seo'])) {
      return [];
    }
    $page = $this->contexts->example();
    $result = $this->renderer->executeInRenderContext(new RenderContext(), fn() => $this->collector->preview($context->entity, $page));
    $context->report('Schema (JSON-LD)', ComponentPieceCollector::json($result['nodes']));
    return array_map(fn(array $piece) => [
      'message' => sprintf(
        'The %s%s would be left out of the page’s schema: it has no %s.',
        $piece['type'],
        $piece['index'] !== NULL ? ' of row ' . $piece['index'] : '',
        implode(', ', $piece['missing']),
      ),
    ], $result['dropped']);
  }

}
