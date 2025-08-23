<?php

declare(strict_types=1);

namespace Drupal\project_context_connector\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Validates whether a request's Origin is allow-listed.
 *
 * Supports exact matches and wildcard subdomain patterns like "*.example.com".
 */
final class OriginValidator {

  /**
   * @var string[]
   */
  private array $allowed;

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {
    $this->allowed = array_filter(array_map('trim', (array) $this->configFactory
      ->get('project_context_connector.settings')
      ->get('allowed_origins') ?? []));
  }

  /**
   * Returns the allowed Origin string if permitted, otherwise null.
   *
   * If no Origin header is present, returns null (treated as same-origin or
   * non-CORS request elsewhere). This method performs no side effects.
   */
  public function allowedOriginFor(Request $request): ?string {
    $origin = (string) $request->headers->get('Origin', '');
    if ($origin === '') {
      return NULL;
    }
    foreach ($this->allowed as $pattern) {
      if ($this->matches($origin, $pattern)) {
        return $origin;
      }
    }
    return NULL;
  }

  /**
   * Exact or wildcard subdomain match.
   *
   * @param string $origin
   *   E.g. "https://sub.example.com".
   * @param string $pattern
   *   E.g. "https://example.com" or "*.example.com".
   */
  private function matches(string $origin, string $pattern): bool {
    $normalizedOrigin = rtrim($origin, '/');
    $normalizedPattern = rtrim($pattern, '/');

    // Exact match including scheme and host.
    if (strcasecmp($normalizedOrigin, $normalizedPattern) === 0) {
      return TRUE;
    }

    // Wildcard subdomain: "*.example.com".
    if (str_starts_with($normalizedPattern, '*.') || str_starts_with($normalizedPattern, 'https://*.') || str_starts_with($normalizedPattern, 'http://*.')) {
      // Extract host portions.
      $originHost = parse_url($normalizedOrigin, PHP_URL_HOST);
      $patternHost = preg_replace('/^\w+:\/\//', '', $normalizedPattern);
      $patternHost = ltrim($patternHost, '*.');
      $originHost = (string) $originHost;

      return $originHost !== '' && (strtolower($originHost) === strtolower($patternHost) || str_ends_with(strtolower($originHost), '.' . strtolower($patternHost)));
    }

    return FALSE;
  }

}
