<?php

declare(strict_types=1);

namespace Drupal\project_context_connector\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountProxyInterface;
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
   *   Request stack to obtain client IP.
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
   * @param string $route
   *   Route machine name.
   *
   * @return bool
   *   TRUE if the request is allowed, FALSE if it should be throttled.
   */
  public function check(string $route): bool {
    $conf = $this->configFactory->get('project_context_connector.settings');
    $threshold = (int) ($conf->get('rate_limit_threshold') ?? 0);
    $window = (int) ($conf->get('rate_limit_window') ?? 0);

    // No rate limit configured.
    if ($threshold <= 0 || $window <= 0) {
      return TRUE;
    }

    $event = $this->event($route);
    $id = $this->identifier();

    if (!$this->flood->isAllowed($event, $threshold, $window, $id)) {
      // Optional: uncomment during development to aid diagnosis.
      // $this->logger->warning('Throttled @event for @id', [
      // '@event' => $event,
      // '@id' => $id,
      // ]);.
      return FALSE;
    }

    // Register this request so subsequent calls within the window count.
    $this->flood->register($event, $window, $id);
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
    return (int) ($conf->get('rate_limit_window') ?? 0);
  }

}
