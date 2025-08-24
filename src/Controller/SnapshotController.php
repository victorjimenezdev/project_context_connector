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
use Drupal\project_context_connector\Service\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

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
   *   Renderer service to ensure cacheability metadata bubble up.
   * @param \Drupal\project_context_connector\Service\RateLimiter $rateLimiter
   *   Rate limiter to throttle requests to this endpoint.
   */
  public function __construct(
    private readonly ContextSnapshotter $snapshotter,
    private readonly RendererInterface $renderer,
    private readonly RateLimiter $rateLimiter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('project_context_connector.context_snapshotter'),
      $container->get('renderer'),
      $container->get('project_context_connector.rate_limiter')
    );
  }

  /**
   * Endpoint action: returns the JSON snapshot.
   */
  public function snapshot(): CacheableJsonResponse {
    // Gate the endpoint via the rate limiter.
    if (!$this->rateLimiter->check('project_context_connector.snapshot')) {
      $retry = $this->rateLimiter->retryAfterSeconds();
      $tooMany = new CacheableJsonResponse(['error' => 'Too many requests'], Response::HTTP_TOO_MANY_REQUESTS);
      // Do not cache 429.
      $tooMany->setMaxAge(0);
      $tooMany->headers->set('Retry-After', (string) $retry);
      $tooMany->headers->set('X-Content-Type-Options', 'nosniff');
      return $tooMany;
    }

    $context = new RenderContext();
    $data = $this->renderer->executeInRenderContext($context, function (): array {
      return $this->snapshotter->buildSnapshot();
    });

    $cache = new CacheableMetadata();
    $cache->setCacheMaxAge($data['_meta']['cache']['max_age']);
    $cache->setCacheContexts([
      'user.permissions',
      'headers:Origin',
    // IMPORTANT: make DPC vary by query string.
      'url.query_args',
    ]);
    $cache->setCacheTags([
      'config:system.theme',
      'project_context_connector:snapshot',
    ]);

    $response = new CacheableJsonResponse($data);
    $response->addCacheableDependency($cache);
    $response->setMaxAge($data['_meta']['cache']['max_age']);
    $response->headers->set('X-Content-Type-Options', 'nosniff');

    return $response;
  }

}
