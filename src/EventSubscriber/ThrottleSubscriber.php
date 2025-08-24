<?php

declare(strict_types=1);

namespace Drupal\project_context_connector\EventSubscriber;

use Drupal\Core\Routing\CurrentRouteMatch;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\project_context_connector\Service\RateLimiter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Applies rate limiting to the snapshot routes.
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
    // Run on CONTROLLER so routing has already populated the route.
    return [
      KernelEvents::CONTROLLER => ['onController', 0],
    ];
  }

  /**
   * Enforce throttling on the snapshot routes.
   */
  public function onController(ControllerEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    $protected = [
      'project_context_connector.snapshot',
      'project_context_connector.snapshot_signed',
    ];

    $routeName = (string) $this->routeMatch->getRouteName();
    if (!in_array($routeName, $protected, TRUE)) {
      return;
    }

    $method = $event->getRequest()->getMethod();
    if ($method !== 'GET' && $method !== 'HEAD') {
      return;
    }

    // Shared bucket for both routes.
    if (!$this->limiter->check('snapshot')) {
      // Return 429 and set Retry-After via the exception.
      throw new TooManyRequestsHttpException(
        $this->limiter->retryAfterSeconds(),
        'Too many requests. Please try again later.'
      );
    }
  }

}
