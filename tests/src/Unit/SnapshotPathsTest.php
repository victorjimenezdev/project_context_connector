<?php

declare(strict_types=1);

namespace Drupal\Tests\project_context_connector\Unit;

use Drupal\project_context_connector\SnapshotPaths;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that path comparison follows core path processing.
 */
#[Group('project_context_connector')]
final class SnapshotPathsTest extends UnitTestCase {

  /**
   * Tests variants that core routes to a snapshot route, and others.
   */
  public function testPathVariants(): void {
    $routed = [
      '/project-context-connector/snapshot',
      '/project-context-connector/snapshot/',
      '/PROJECT-context-connector/SNAPSHOT',
      '/project-context-connector/%73napshot',
      '/project-context-connector/%53NAPSHOT',
      '/project%2Dcontext%2Dconnector//snapshot',
    ];
    foreach ($routed as $path) {
      $this->assertTrue(SnapshotPaths::isStandard($path), $path);
      $this->assertTrue(SnapshotPaths::endsWithSnapshotPath($path), $path);
    }
    foreach (['/project-context-connector/%73napshot/signed', '/es/project-context-connector/%73napshot/signed'] as $path) {
      $this->assertFalse(SnapshotPaths::isStandard($path), $path);
      $this->assertTrue(SnapshotPaths::endsWithSnapshotPath($path), $path);
    }
    // Core decodes once, so a double-encoded path is not a snapshot path.
    foreach (['/project-context-connector/%2573napshot', '/project-context-connector/snapshots', '/'] as $path) {
      $this->assertFalse(SnapshotPaths::isStandard($path), $path);
      $this->assertFalse(SnapshotPaths::endsWithSnapshotPath($path), $path);
    }
  }

}
