<?php

declare(strict_types=1);

namespace Drupal\project_context_connector\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Simple IP+route rate limiter using Drupal's Flood service.
 *
 * Flood provides a durable sliding-window counter. We use it to limit
 * repeated access to the snapshot endpoint.
 */
final class RateLimiter {

  public function __construct(
    private readonly FloodInterface $flood,
    private readonly RequestStack $requestStack,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * Check and register a hit. Returns TRUE if allowed, FALSE if throttled.
   *
   * @param string $key
   *   A logical key, e.g., "snapshot".
   */
  public function check(string $key): bool {
    $request = $this->requestStack->getCurrentRequest();
    $ip = $request instanceof Request ? (string) $request->getClientIp() : '0.0.0.0';

    $conf = $this->configFactory->get('project_context_connector.settings');
    $threshold = max(1, (int) $conf->get('rate_limit_threshold') ?: 60);
    $window = max(1, (int) $conf->get('rate_limit_window') ?: 60);

    $floodKey = "project_context_connector:{$key}:{$ip}";
    $allowed = $this->flood->isAllowed($floodKey, $threshold, $window);

    if ($allowed) {
      $this->flood->register($floodKey, $window);
    }
    else {
      $this->logger->notice('Rate limit exceeded for @ip on key @key', ['@ip' => $ip, '@key' => $key]);
    }

    return $allowed;
  }

  /**
   * Returns configured Retry-After seconds (best-effort).
   */
  public function retryAfterSeconds(): int {
    $window = (int) $this->configFactory->get('project_context_connector.settings')->get('rate_limit_window') ?: 60;
    return max(1, $window);
  }

}
