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
   * Array contains allowed origins.
   *
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
   * Wildcard patterns (*.example.com) match ONLY subdomains, not the base
   * domain. To match both, add both patterns: ["https://example.com",
   * "https://*.example.com"].
   *
   * @param string $origin
   *   E.g. "https://sub.example.com".
   * @param string $pattern
   *   E.g. "https://example.com" or "https://*.example.com".
   */
  private function matches(string $origin, string $pattern): bool {
    $normalizedOrigin = rtrim($origin, '/');
    $normalizedPattern = rtrim($pattern, '/');

    // Exact match including scheme and host.
    if (strcasecmp($normalizedOrigin, $normalizedPattern) === 0) {
      return TRUE;
    }

    // Wildcard subdomain: "*.example.com" or "https://*.example.com".
    if (str_starts_with($normalizedPattern, '*.') || str_starts_with($normalizedPattern, 'https://*.') || str_starts_with($normalizedPattern, 'http://*.')) {
      // Extract scheme and host from origin.
      $originScheme = parse_url($normalizedOrigin, PHP_URL_SCHEME);
      $originHost = parse_url($normalizedOrigin, PHP_URL_HOST);

      // Extract scheme and host from pattern.
      $patternScheme = parse_url($normalizedPattern, PHP_URL_SCHEME);
      if ($patternScheme === NULL && str_starts_with($normalizedPattern, '*.')) {
        // Pattern like "*.example.com" without scheme defaults to https.
        $patternScheme = 'https';
      }

      $patternHost = preg_replace('/^\w+:\/\//', '', $normalizedPattern);
      $patternHost = ltrim($patternHost, '*.');

      // Ensure schemes match (security: don't allow http pattern to match
      // https origin or vice versa).
      if ($originScheme !== $patternScheme) {
        return FALSE;
      }

      // Wildcard should match ONLY subdomains, not the base domain itself.
      // For "*.example.com" to match "example.com", add both patterns
      // explicitly.
      $originHost = (string) $originHost;
      $patternHost = (string) $patternHost;

      return $originHost !== '' &&
        $originHost !== $patternHost &&
        str_ends_with(strtolower($originHost), '.' . strtolower($patternHost));
    }

    return FALSE;
  }

}
