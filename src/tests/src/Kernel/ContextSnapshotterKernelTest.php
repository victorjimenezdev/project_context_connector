<?php

declare(strict_types=1);

namespace Drupal\Tests\project_context_connector\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * @group project_context_connector
 */
final class ContextSnapshotterKernelTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'project_context_connector',
  ];

  /**
   *
   */
  public function testSnapshotStructure(): void {
    $snapshotter = $this->container->get('project_context_connector.context_snapshotter');
    $data = $snapshotter->buildSnapshot();

    self::assertArrayHasKey('generated_at', $data);
    self::assertArrayHasKey('drupal', $data);
    self::assertIsArray($data['drupal']);
    self::assertArrayHasKey('core_version', $data['drupal']);
    self::assertArrayHasKey('active_modules', $data['drupal']);
    self::assertIsArray($data['drupal']['active_modules']);

    // Ensure module enumerates at least our own module.
    $hasSelf = FALSE;
    foreach ($data['drupal']['active_modules'] as $mod) {
      if (($mod['name'] ?? NULL) === 'project_context_connector') {
        $hasSelf = TRUE;
        break;
      }
    }
    self::assertTrue($hasSelf, 'Self module should be listed as active.');
  }

}
