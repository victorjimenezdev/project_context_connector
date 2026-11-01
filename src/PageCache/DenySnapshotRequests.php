<?php

declare(strict_types=1);

namespace Drupal\project_context_connector\PageCache;

use Drupal\Core\PageCache\RequestPolicyInterface;
use Drupal\project_context_connector\SnapshotPaths;
use Symfony\Component\HttpFoundation\Request;

/**
 * Keeps snapshot requests out of the page caches.
 *
 * Page cache runs before routing and access checks. A cached response would
 * skip authorization and quota, and a cached 403 would reject valid signatures.
 */
final class DenySnapshotRequests implements RequestPolicyInterface {

  /**
   * {@inheritdoc}
   */
  public function check(Request $request): ?string {
    return SnapshotPaths::endsWithSnapshotPath($request->getPathInfo()) ? self::DENY : NULL;
  }

}
