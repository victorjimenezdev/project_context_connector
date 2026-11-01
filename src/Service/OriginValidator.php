<?php

declare(strict_types=1);

namespace Drupal\project_context_connector\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Validates a request Origin against the configured allow-list.
 *
 * A pattern is "scheme://host[:port]". The host can start with "*." to match
 * subdomains only. A bare "*.example.com" means https, for 1.x compatibility.
 * Scheme, host and effective port must all match.
 */
final class OriginValidator {

  private const array DEFAULT_PORTS = ['http' => 80, 'https' => 443];

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Returns the request Origin if it is allowed, otherwise NULL.
   */
  public function allowedOriginFor(Request $request): ?string {
    $origin = (string) $request->headers->get('Origin', '');
    $parsed = self::parse($origin, FALSE);
    if ($parsed === NULL) {
      return NULL;
    }
    $patterns = $this->configFactory->get('project_context_connector.settings')->get('allowed_origins');
    foreach (is_array($patterns) ? $patterns : [] as $pattern) {
      $allowed = is_string($pattern) ? self::parse(self::expandBareWildcard(trim($pattern)), TRUE) : NULL;
      if ($allowed !== NULL && self::matches($parsed, $allowed)) {
        return $origin;
      }
    }
    return NULL;
  }

  /**
   * Whether a string is a valid allow-list pattern.
   */
  public static function isValidPattern(string $pattern): bool {
    return self::parse(self::expandBareWildcard($pattern), TRUE) !== NULL;
  }

  /**
   * Applies the 1.x meaning of a pattern with no scheme.
   */
  private static function expandBareWildcard(string $pattern): string {
    return str_starts_with($pattern, '*.') ? 'https://' . $pattern : $pattern;
  }

  /**
   * Parses an origin or a pattern.
   *
   * The strict expression has no place for userinfo, a path, a query or a
   * fragment, so those values never parse.
   *
   * @return array{scheme: string, host: string, port: int, wildcard: bool}|null
   *   The normalized parts, or NULL when the value is malformed.
   */
  private static function parse(string $value, bool $allowWildcard): ?array {
    $matched = preg_match('@^(https?)://(\*\.)?([^/?#:\@\[\]\s]+|\[[0-9a-f:.]+\])(?::([0-9]{1,5}))?$@iD', $value, $parts);
    if ($matched !== 1) {
      return NULL;
    }
    $wildcard = $parts[2] !== '';
    if ($wildcard && !$allowWildcard) {
      return NULL;
    }
    $scheme = strtolower($parts[1]);
    $host = strtolower($parts[3]);
    if (!self::isValidHost($host, $wildcard)) {
      return NULL;
    }
    $port = isset($parts[4]) ? (int) $parts[4] : self::DEFAULT_PORTS[$scheme];
    if ($port < 1 || $port > 65535) {
      return NULL;
    }
    return ['scheme' => $scheme, 'host' => $host, 'port' => $port, 'wildcard' => $wildcard];
  }

  /**
   * Validates a lowercase host.
   */
  private static function isValidHost(string $host, bool $wildcard): bool {
    if ($host[0] === '[') {
      return !$wildcard && filter_var(trim($host, '[]'), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== FALSE;
    }
    if (strlen($host) > 253) {
      return FALSE;
    }
    $labels = explode('.', $host);
    foreach ($labels as $label) {
      if (preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/D', $label) !== 1) {
        return FALSE;
      }
    }
    // A wildcard over a bare suffix such as "*.com" would match everything.
    return !$wildcard || count($labels) >= 2;
  }

  /**
   * Compares a parsed origin with a parsed pattern.
   *
   * @param array{scheme: string, host: string, port: int, wildcard: bool} $origin
   *   The request origin.
   * @param array{scheme: string, host: string, port: int, wildcard: bool} $pattern
   *   The allow-list pattern.
   */
  private static function matches(array $origin, array $pattern): bool {
    if ($origin['scheme'] !== $pattern['scheme'] || $origin['port'] !== $pattern['port']) {
      return FALSE;
    }
    if (!$pattern['wildcard']) {
      return $origin['host'] === $pattern['host'];
    }
    return str_ends_with($origin['host'], '.' . $pattern['host']);
  }

}
