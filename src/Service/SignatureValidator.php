<?php

declare(strict_types=1);

namespace Drupal\project_context_connector\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Site\Settings;
use Symfony\Component\HttpFoundation\Request;

/**
 * Validates HMAC signed requests for the signed snapshot route.
 *
 * Signature scheme:
 *   base = "<METHOD>\n<PATH>\n<TIMESTAMP>"
 *   signature = hex( HMAC-SHA256( base, secret ) )
 *
 * Required headers: X-PCC-Key, X-PCC-Timestamp, X-PCC-Signature.
 *
 * There is no nonce. A captured request can be replayed until its timestamp
 * leaves the window, so clients must use TLS.
 *
 * Secrets are stored in settings.php:
 *   $settings['project_context_connector_api_keys'] = [
 *     'prompt-bot' => 'strong-random-secret',
 *   ];
 */
class SignatureValidator {

  public const int WINDOW_SECONDS = 300;

  public function __construct(
    private readonly Settings $settings,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Validates the signature of a request.
   */
  public function isValid(Request $request): bool {
    $keyId = trim((string) $request->headers->get('X-PCC-Key', ''));
    $timestamp = trim((string) $request->headers->get('X-PCC-Timestamp', ''));
    $signature = strtolower(trim((string) $request->headers->get('X-PCC-Signature', '')));
    if ($keyId === '' || $timestamp === '' || $signature === '') {
      return FALSE;
    }

    $keys = $this->settings->get('project_context_connector_api_keys', []);
    $secret = is_array($keys) ? ($keys[$keyId] ?? NULL) : NULL;
    if (!is_string($secret) || $secret === '') {
      return FALSE;
    }

    // Unix seconds only: digits, no leading zero, within the window.
    if (preg_match('/^[1-9][0-9]{0,10}$/D', $timestamp) !== 1) {
      return FALSE;
    }
    if (abs($this->time->getRequestTime() - (int) $timestamp) > self::WINDOW_SECONDS) {
      return FALSE;
    }

    $base = $request->getMethod() . "\n" . $request->getPathInfo() . "\n" . $timestamp;
    return hash_equals(hash_hmac('sha256', $base, $secret), $signature);
  }

}
