<?php

declare(strict_types=1);

namespace Drupal\project_context_connector\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Site\Settings;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Validates HMAC signed requests for the signed snapshot route.
 *
 * Signature scheme:
 *   base = "<METHOD>\n<PATH>\n<TIMESTAMP>"
 *   signature = hex( HMAC-SHA256( base, secret ) )
 *
 * Required headers:
 *   X-PCC-Key, X-PCC-Timestamp, X-PCC-Signature
 *
 * Secrets are stored in settings.php under:
 *   $settings['project_context_connector_api_keys'] = [
 *     'prompt-bot' => 'strong-random-secret',
 *   ];
 */
final class SignatureValidator {

  public function __construct(
    private readonly Settings $settings,
    private readonly RequestStack $requestStack,
    private readonly TimeInterface $time,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * Validate the current request signature.
   *
   * @param int $skewSeconds
   *   Allowed clock skew in seconds (default 300).
   *
   * @return bool
   *   TRUE if the signature is valid for the current request.
   */
  public function isValid(int $skewSeconds = 300): bool {
    $req = $this->requestStack->getCurrentRequest();
    if (!$req instanceof Request) {
      return FALSE;
    }

    $keyId = trim((string) $req->headers->get('X-PCC-Key', ''));
    $tsStr = trim((string) $req->headers->get('X-PCC-Timestamp', ''));
    $sig   = strtolower(trim((string) $req->headers->get('X-PCC-Signature', '')));

    if ($keyId === '' || $tsStr === '' || $sig === '') {
      return FALSE;
    }

    // Load secret from settings.php.
    /** @var array<string,string> $keys */
    $keys = (array) $this->settings->get('project_context_connector_api_keys', []);
    $secret = $keys[$keyId] ?? NULL;
    if (!is_string($secret) || $secret === '') {
      return FALSE;
    }

    // Timestamp must be unix seconds and within skew.
    // Validate format: must be all digits, no leading zeros (except "0" itself),
    // no negative numbers, and reasonable length (10-11 digits for unix time).
    if (!ctype_digit($tsStr) || strlen($tsStr) > 11 || strlen($tsStr) < 1) {
      return FALSE;
    }
    // Reject leading zeros (except "0" itself).
    if (strlen($tsStr) > 1 && $tsStr[0] === '0') {
      return FALSE;
    }
    $ts = (int) $tsStr;
    // Sanity check: timestamp should be reasonable (after 2000, before 2100).
    if ($ts < 946684800 || $ts > 4102444800) {
      return FALSE;
    }
    $now = (int) $this->time->getRequestTime();
    if (abs($now - $ts) > max(1, $skewSeconds)) {
      return FALSE;
    }

    // Canonical string: METHOD + PATH (no query) + TIMESTAMP.
    $base = $req->getMethod() . "\n" . $req->getPathInfo() . "\n" . $tsStr;
    $expected = hash_hmac('sha256', $base, $secret);

    return hash_equals($expected, $sig);
  }

}
