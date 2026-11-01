<?php

declare(strict_types=1);

namespace Drupal\project_context_connector\EventSubscriber;

use Drupal\Core\Session\AccountInterface;
use Drupal\project_context_connector\Routing\FailedAuthEnhancer;
use Drupal\project_context_connector\Service\RateLimiter;
use Drupal\project_context_connector\SnapshotPaths;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Enforces the authorized quota and records failed authorization.
 *
 * FailedAuthEnhancer applies the failed-authorization block during routing.
 *
 * Only GET and HEAD count. Preflight carries no credentials and returns no
 * payload, so it uses no quota. Page caches cannot serve these routes, so every
 * authorized request reaches this subscriber and uses one unit.
 */
final class ThrottleSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly RateLimiter $limiter,
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      // After routing and access: only authorized requests get here.
      KernelEvents::REQUEST => ['onAuthorized', 0],
      // Before core renders or logs the 4xx response.
      KernelEvents::EXCEPTION => ['onException', 100],
    ];
  }

  /**
   * Uses one unit of each applicable quota for an authorized request.
   */
  public function onAuthorized(RequestEvent $event): void {
    $request = $event->getRequest();
    if (!$event->isMainRequest() || !SnapshotPaths::isSnapshotRoute($request->attributes->get('_route'))) {
      return;
    }
    if (!$this->limiter->consumeQuota(...$this->quotaIds($request))) {
      $event->setResponse($this->tooManyRequests($this->limiter->quotaRetryAfter()));
    }
  }

  /**
   * Answers a blocked request, and records a denied request as a failure.
   */
  public function onException(ExceptionEvent $event): void {
    $request = $event->getRequest();
    if (!$event->isMainRequest()) {
      return;
    }
    // A blocked client gets 429 here with no further writes, also to logs.
    if ($request->attributes->get(FailedAuthEnhancer::BLOCKED_ATTRIBUTE) === TRUE) {
      $event->setResponse($this->tooManyRequests($this->limiter->failureRetryAfter()));
      $event->stopPropagation();
      return;
    }
    $exception = $event->getThrowable();
    if (SnapshotPaths::isSnapshotRoute($request->attributes->get('_route'))
      && ($exception instanceof AccessDeniedHttpException || $exception instanceof UnauthorizedHttpException)) {
      $this->limiter->registerFailure($request);
    }
  }

  /**
   * Returns the quota identities of an authorized request.
   *
   * The IP identity comes first and always applies, so a client cannot gain
   * more requests by changing its credentials or endpoint.
   *
   * @return string[]
   *   The identities.
   */
  private function quotaIds(Request $request): array {
    $ids = [$this->limiter->ipId($request)];
    if ($request->attributes->get('_route') === SnapshotPaths::SIGNED_ROUTE) {
      $ids[] = RateLimiter::keyClientId(trim((string) $request->headers->get('X-PCC-Key', '')));
    }
    elseif ($this->currentUser->isAuthenticated()) {
      $ids[] = 'uid:' . $this->currentUser->id();
    }
    return $ids;
  }

  /**
   * Builds a 429 response.
   */
  private function tooManyRequests(int $retryAfter): JsonResponse {
    $response = new JsonResponse(['message' => 'Too many requests. Please try again later.'], 429);
    $response->headers->set('Retry-After', (string) $retryAfter);
    $response->headers->set('Cache-Control', 'private, no-store');
    return $response;
  }

}
