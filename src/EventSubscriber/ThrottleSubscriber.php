<?php

declare(strict_types=1);

namespace Drupal\project_context_connector\EventSubscriber;

use Drupal\Core\Routing\CurrentRouteMatch;
use Drupal\project_context_connector\Service\RateLimiter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Applies rate limiting to the snapshot route.
 */
final class ThrottleSubscriber implements EventSubscriberInterface {
  use StringTranslationTrait;

  public function __construct(
    private readonly RateLimiter $limiter,
    private readonly CurrentRouteMatch $routeMatch,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::REQUEST => ['onRequest', 35],
    ];
  }

  /**
   * Throttle read-only snapshot routes using the limiter.
   */
  public function onRequest(RequestEvent $event): void {
    if ($event->isMainRequest() === FALSE) {
      return;
    }

    $request = $event->getRequest();

    $protectedRoutes = [
      'project_context_connector.snapshot',
      'project_context_connector.snapshot_signed',
    ];

    $route = (string) $request->attributes->get('_route', '');
    if (!in_array($route, $protectedRoutes, TRUE)) {
      return;
    }

    // Only throttle actual reads; allow OPTIONS preflight to pass.
    $method = $request->getMethod();
    if ($method !== 'GET' && $method !== 'HEAD') {
      return;
    }

    // Use a single bucket for both routes so they share the same budget.
    if (!$this->limiter->check('snapshot')) {
      $response = new JsonResponse([
        'message' => $this->t('Too many requests. Please try again later.'),
      ], 429);
      $response->headers->set('Retry-After', (string) $this->limiter->retryAfterSeconds());
      $response->headers->set('X-Content-Type-Options', 'nosniff');
      $event->setResponse($response);
    }
  }

}
