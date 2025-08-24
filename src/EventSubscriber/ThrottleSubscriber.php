<?php

declare(strict_types=1);

namespace Drupal\project_context_connector\EventSubscriber;

use Drupal\Core\Routing\CurrentRouteMatch;
use Drupal\project_context_connector\Service\RateLimiter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Applies simple request throttling to snapshot routes.
 */
final class ThrottleSubscriber implements EventSubscriberInterface {

  /**
   * Constructs the throttling subscriber.
   *
   * @param \Drupal\project_context_connector\Service\RateLimiter $limiter
   *   Rate limiter service.
   * @param \Drupal\Core\Routing\CurrentRouteMatch $routeMatch
   *   Current route match.
   */
  public function __construct(
    private RateLimiter $limiter,
    private CurrentRouteMatch $routeMatch,
  ) {}

  /**
   * React on kernel.request and throttle snapshot endpoints.
   */
  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    $request = $event->getRequest();
    $route = (string) $request->attributes->get('_route', '');

    // Protect both plain and signed endpoints.
    $protected = [
      'project_context_connector.snapshot',
      'project_context_connector.snapshot_signed',
    ];
    if (!in_array($route, $protected, TRUE)) {
      return;
    }

    // Only throttle safe reads; allow OPTIONS preflight.
    $method = $request->getMethod();
    if ($method !== 'GET' && $method !== 'HEAD') {
      return;
    }

    // Use the route name as the action so the limiter key is stable and
    // does not vary by query string.
    if (!$this->limiter->check($route)) {
      $response = new JsonResponse([
        'message' => 'Too many requests. Please try again later.',
      ], 429);
      $response->headers->set(
        'Retry-After',
        (string) $this->limiter->retryAfterSeconds()
      );
      $event->setResponse($response);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Run after routing so _route is available.
    return [
      KernelEvents::REQUEST => ['onRequest', 0],
    ];
  }

}
