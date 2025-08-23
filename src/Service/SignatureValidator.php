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
    // IMPORTANT: Use the Component interface. Fully-qualified to avoid wrong imports.
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
    if (!ctype_digit($tsStr)) {
      return FALSE;
    }
    $ts = (int) $tsStr;
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
