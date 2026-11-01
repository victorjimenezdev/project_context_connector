<?php

declare(strict_types=1);

namespace Drupal\project_context_connector\Routing;

use Drupal\Core\Routing\EnhancerInterface;
use Drupal\project_context_connector\Service\RateLimiter;
use Drupal\project_context_connector\SnapshotPaths;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * Blocks a client with too many failed attempts before its access check runs.
 *
 * Route enhancers run after Drupal resolves the route and before the access
 * check. The route name is the result of core path processing, so an encoded
 * path, a language prefix or a path alias cannot avoid this block.
 */
final class FailedAuthEnhancer implements EnhancerInterface {

  /**
   * The request attribute that marks a blocked request.
   */
  public const string BLOCKED_ATTRIBUTE = '_pcc_failure_blocked';

  public function __construct(
    private readonly RateLimiter $limiter,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function enhance(array $defaults, Request $request): array {
    if (SnapshotPaths::isSnapshotRoute($defaults['_route'] ?? NULL)
      && $request === $this->requestStack->getMainRequest()
      && $this->limiter->isFailureBlocked($request)) {
      $request->attributes->set(self::BLOCKED_ATTRIBUTE, TRUE);
      throw new TooManyRequestsHttpException($this->limiter->failureRetryAfter());
    }
    return $defaults;
  }

}
