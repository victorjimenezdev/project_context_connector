<?php

declare(strict_types=1);

namespace Drupal\project_context_connector\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Per-route rate limiter using Flood.
 *
 * Identifies clients by authenticated user ID; falls back to IP for anonymous
 * requests. The Flood identifier is passed explicitly to avoid ambiguity in
 * proxied environments.
 */
final class RateLimiter {

  /**
   * Constructs a RateLimiter service.
   *
   * @param \Drupal\Core\Flood\FloodInterface $flood
   *   Flood service.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   Request stack to obtain client IP and store per-request memoization.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Config factory.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   Current user proxy.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   Logger channel for diagnostics.
   */
  public function __construct(
    private FloodInterface $flood,
    private RequestStack $requestStack,
    private ConfigFactoryInterface $configFactory,
    private AccountProxyInterface $currentUser,
    private LoggerChannelInterface $logger,
  ) {}

  /**
   * Returns a stable Flood event name for a route.
   *
   * @param string $route
   *   Route machine name.
   *
   * @return string
   *   Flood event name (<= 64 chars).
   */
  private function event(string $route): string {
    // Keep this short to fit the flood.event varchar(64) column.
    return "pcc.$route";
  }

  /**
   * Builds the per-request attribute key for memoizing results.
   *
   * @param string $route
   *   Route machine name.
   *
   * @return string
   *   Request attribute key.
   */
  private function attributeKey(string $route): string {
    return 'pcc.rate_check.' . $route;
  }

  /**
   * Returns a stable client identifier (uid or IP).
   *
   * @return string
   *   Identifier in the form "uid:123" or "ip:127.0.0.1".
   */
  private function identifier(): string {
    if ($this->currentUser->isAuthenticated()) {
      return 'uid:' . (string) $this->currentUser->id();
    }
    $ip = $this->requestStack->getCurrentRequest()?->getClientIp() ?? 'unknown';
    return 'ip:' . $ip;
  }

  /**
   * Checks if a request is allowed and registers it when allowed.
   *
   * Multiple calls within the same HTTP request will return the same result and
   * will only register once in Flood to avoid double-counting.
   *
   * @param string $route
   *   Route machine name.
   *
   * @return bool
   *   TRUE if the request is allowed, FALSE if it should be throttled.
   */
  public function check(string $route): bool {
    $request = $this->requestStack->getCurrentRequest();

    // Return memoized result if available for this request.
    if ($request instanceof Request) {
      $attrKey = $this->attributeKey($route);
      $cached = $request->attributes->get($attrKey);
      if (is_bool($cached)) {
        return $cached;
      }
    }

    $conf = $this->configFactory->get('project_context_connector.settings');
    $threshold = (int) ($conf->get('rate_limit_threshold') ?? 0);
    $window = (int) ($conf->get('rate_limit_window') ?? 0);

    // No rate limit configured.
    if ($threshold <= 0 || $window <= 0) {
      if ($request instanceof Request) {
        $request->attributes->set($this->attributeKey($route), TRUE);
      }
      return TRUE;
    }

    $event = $this->event($route);
    $id = $this->identifier();

    $allowed = $this->flood->isAllowed($event, $threshold, $window, $id);
    if ($allowed) {
      // Register this request so subsequent calls within the window count.
      $this->flood->register($event, $window, $id);
    }

    if ($request instanceof Request) {
      $request->attributes->set($this->attributeKey($route), $allowed);
    }

    return $allowed;
  }

  /**
   * Returns the configured wait time before retrying.
   *
   * @return int
   *   Seconds to wait before retrying.
   */
  public function retryAfterSeconds(): int {
    $conf = $this->configFactory->get('project_context_connector.settings');
    return (int) ($conf->get('rate_limit_window') ?? 0);
  }

}
