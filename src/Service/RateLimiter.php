<?php

declare(strict_types=1);

namespace Drupal\project_context_connector\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Simple per-route, per-IP rate limiter built on Flood API.
 */
final class RateLimiter {

  /**
   * Constructs a RateLimiter service.
   *
   * @param \Drupal\Core\Flood\FloodInterface $flood
   *   Flood service.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   Request stack to obtain client IP and route name.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Config factory.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   Logger channel for diagnostics.
   */
  public function __construct(
    private FloodInterface $flood,
    private RequestStack $requestStack,
    private ConfigFactoryInterface $configFactory,
    private LoggerChannelInterface $logger,
  ) {}

  /**
   * Builds a Flood key that is stable across query strings.
   *
   * Only the route name and client IP are used, to ensure that
   * successive requests to the same endpoint count toward the same
   * window regardless of query parameters.
   *
   * @param string $action
   *   Action or default route name if request does not supply one.
   *
   * @return string
   *   Stable key in the form "project_context_connector.<route>:<ip>".
   */
  private function buildKey(string $action): string {
    $request = $this->requestStack->getCurrentRequest();
    $ip = $request?->getClientIp() ?? 'unknown';
    // Prefer route name from attributes; fall back to the provided action.
    $route = (string) ($request?->attributes->get('_route') ?? $action);
    return "project_context_connector.$route:$ip";
  }

  /**
   * Checks if a request is allowed and registers it when allowed.
   *
   * @param string $action
   *   Action name, usually the route name.
   *
   * @return bool
   *   TRUE if the request is allowed, FALSE if it should be throttled.
   */
  public function check(string $action): bool {
    $conf = $this->configFactory->get('project_context_connector.settings');
    $threshold = (int) $conf->get('rate_limit_threshold') ?: 0;
    $window = (int) $conf->get('rate_limit_window') ?: 0;

    // No rate limit configured.
    if ($threshold <= 0 || $window <= 0) {
      return TRUE;
    }

    $key = $this->buildKey($action);

    if (!$this->flood->isAllowed($key, $threshold, $window)) {
      return FALSE;
    }

    // Register this request so subsequent calls within the window count.
    $this->flood->register($key, $window);
    return TRUE;
  }

  /**
   * Returns the configured wait time before retrying.
   *
   * @return int
   *   Seconds to wait before retrying.
   */
  public function retryAfterSeconds(): int {
    $conf = $this->configFactory->get('project_context_connector.settings');
    return (int) $conf->get('rate_limit_window') ?: 0;
  }

}
