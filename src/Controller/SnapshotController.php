<?php

declare(strict_types=1);

namespace Drupal\project_context_connector\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Render\RendererInterface;
use Drupal\project_context_connector\Service\ContextSnapshotter;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for the read-only project context snapshot.
 *
 * Returns a sanitized JSON payload useful for Slack/Teams prompt building.
 * Caching and CORS are handled via configuration, event subscribers, and
 * cache metadata attached to the response.
 */
final class SnapshotController implements ContainerInjectionInterface {

  /**
   * Constructor.
   *
   * @param \Drupal\project_context_connector\Service\ContextSnapshotter $snapshotter
   *   Service that builds the snapshot array.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   Renderer service to ensure cacheability metadata bubble-up (defensive)
   */
  public function __construct(
    private readonly ContextSnapshotter $snapshotter,
    private readonly RendererInterface $renderer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('project_context_connector.context_snapshotter'),
      $container->get('renderer'),
    );
  }

  /**
   * Endpoint action: returns the JSON snapshot.
   */
  public function snapshot(): CacheableJsonResponse {
    $context = new RenderContext();
    $data = $this->renderer->executeInRenderContext($context, function (): array {
      return $this->snapshotter->buildSnapshot();
    });

    $cache = new CacheableMetadata();
    $cache->setCacheMaxAge($data['_meta']['cache']['max_age']);
    // Conservative contexts to avoid leaking across permission/origin.
    $cache->setCacheContexts([
      'user.permissions',
      'headers:Origin',
    ]);
    // Tags that represent inputs to the snapshot.
    $cache->setCacheTags([
      'config:system.theme',
      'project_context_connector:snapshot',
    ]);

    $response = new CacheableJsonResponse($data);
    // Attach cacheability correctly to the HTTP response.
    $response->addCacheableDependency($cache);
    // Ensure the max-age header is explicitly present.
    $response->setMaxAge($data['_meta']['cache']['max_age']);

    // Extra safety header.
    $response->headers->set('X-Content-Type-Options', 'nosniff');

    return $response;
  }

}
