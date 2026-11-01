<?php

declare(strict_types=1);

namespace Drupal\project_context_connector\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Flood\FloodInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Applies the snapshot quota and the failed-authorization limit with Flood.
 *
 * The authorized quota has one event for both routes. Each request counts once
 * against its IP address, and once against its user ID or hashed HMAC key ID
 * when it has one. All clients behind one IP address therefore share one
 * allowance, and a credential cannot exceed the threshold from several IP
 * addresses.
 *
 * Failed attempts count per IP plus claimed key. The whole IP has a limit of
 * five times that threshold, so a neighbor behind a shared IP cannot easily
 * lock out a client that uses a different key.
 */
class RateLimiter {

  private const string QUOTA_EVENT = 'pcc.snapshot';
  private const string FAILURE_EVENT = 'pcc.auth_failure';
  private const int IP_FAILURE_MULTIPLIER = 5;

  public function __construct(
    private readonly FloodInterface $flood,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Consumes one unit from each identity, or none when any is exhausted.
   */
  public function consumeQuota(string ...$clientIds): bool {
    $threshold = $this->setting('rate_limit_threshold');
    $window = $this->setting('rate_limit_window');
    if ($threshold <= 0 || $window <= 0) {
      return TRUE;
    }
    $clientIds = array_unique($clientIds);
    foreach ($clientIds as $clientId) {
      if (!$this->flood->isAllowed(self::QUOTA_EVENT, $threshold, $window, $clientId)) {
        return FALSE;
      }
    }
    foreach ($clientIds as $clientId) {
      $this->flood->register(self::QUOTA_EVENT, $window, $clientId);
    }
    return TRUE;
  }

  /**
   * Whether failed attempts currently block this request.
   */
  public function isFailureBlocked(Request $request): bool {
    $threshold = $this->setting('failed_auth_threshold');
    $window = $this->setting('failed_auth_window');
    if ($threshold <= 0 || $window <= 0) {
      return FALSE;
    }
    return !$this->flood->isAllowed(self::FAILURE_EVENT, $threshold, $window, $this->failureId($request))
      || !$this->flood->isAllowed(self::FAILURE_EVENT, $threshold * self::IP_FAILURE_MULTIPLIER, $window, $this->ipId($request));
  }

  /**
   * Records a failed attempt.
   */
  public function registerFailure(Request $request): void {
    $window = $this->setting('failed_auth_window');
    if ($window > 0) {
      $this->flood->register(self::FAILURE_EVENT, $window, $this->failureId($request));
      $this->flood->register(self::FAILURE_EVENT, $window, $this->ipId($request));
    }
  }

  /**
   * Returns the seconds a throttled client should wait.
   */
  public function quotaRetryAfter(): int {
    return $this->setting('rate_limit_window');
  }

  /**
   * Returns the seconds a blocked client should wait.
   */
  public function failureRetryAfter(): int {
    return $this->setting('failed_auth_window');
  }

  /**
   * Returns a non-reversible quota identity for an HMAC key ID.
   */
  public static function keyClientId(string $keyId): string {
    return 'key:' . hash('sha256', $keyId);
  }

  /**
   * Returns the IP identity of a request.
   */
  public function ipId(Request $request): string {
    return 'ip:' . ($request->getClientIp() ?? 'unknown');
  }

  /**
   * Returns the IP plus claimed key identity of a request.
   */
  private function failureId(Request $request): string {
    $keyId = trim((string) $request->headers->get('X-PCC-Key', ''));
    return $this->ipId($request) . '|' . hash('sha256', $keyId);
  }

  /**
   * Reads an integer setting.
   */
  private function setting(string $name): int {
    return (int) $this->configFactory->get('project_context_connector.settings')->get($name);
  }

}
