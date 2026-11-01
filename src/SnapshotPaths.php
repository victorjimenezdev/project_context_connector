<?php

declare(strict_types=1);

namespace Drupal\project_context_connector;

/**
 * Identifies snapshot requests before routing has run.
 *
 * These checks cannot see path aliases. Code that runs before routing uses them
 * only as a first filter: the route's no_cache option covers aliased responses,
 * and FailedAuthEnhancer applies limits to the resolved route.
 */
final class SnapshotPaths {

  public const string STANDARD_ROUTE = 'project_context_connector.snapshot';
  public const string SIGNED_ROUTE = 'project_context_connector.snapshot_signed';

  private const string STANDARD_PATH = '/project-context-connector/snapshot';
  private const string SIGNED_PATH = '/project-context-connector/snapshot/signed';

  /**
   * Whether the path is the standard snapshot path.
   */
  public static function isStandard(string $path): bool {
    return self::normalize($path) === self::STANDARD_PATH;
  }

  /**
   * Whether the path ends in a snapshot path, including a language prefix.
   */
  public static function endsWithSnapshotPath(string $path): bool {
    $normalized = self::normalize($path);
    return str_ends_with($normalized, self::STANDARD_PATH)
      || str_ends_with($normalized, self::SIGNED_PATH);
  }

  /**
   * Whether the route name is a snapshot route.
   */
  public static function isSnapshotRoute(mixed $route): bool {
    return $route === self::STANDARD_ROUTE || $route === self::SIGNED_ROUTE;
  }

  /**
   * Applies the path rules of core routing to a raw request path.
   *
   * PathProcessorDecode decodes the path once with urldecode(). RouteProvider
   * then ignores case, repeated slashes and a trailing slash. Decoding a second
   * time would match paths that core does not route to these routes.
   *
   * Use this only to compare paths. HMAC signatures cover the path as sent.
   */
  private static function normalize(string $path): string {
    $path = (string) preg_replace('@/+@', '/', mb_strtolower(urldecode($path)));
    return rtrim($path, '/');
  }

}
