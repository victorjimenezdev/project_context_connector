<?php

declare(strict_types=1);

namespace Drupal\Tests\project_context_connector\Traits;

use Drupal\project_context_connector\SnapshotPaths;
use Drupal\update\UpdateManagerInterface;
use JsonSchema\Validator;

/**
 * Checks serialized snapshots against the published schema.
 */
trait SnapshotSchemaTrait {

  /**
   * Validates data against the published schema after JSON encoding.
   */
  protected function assertMatchesSchema(mixed $data): void {
    $validator = new Validator();
    $root = dirname((new \ReflectionClass(SnapshotPaths::class))->getFileName(), 2);
    $schema = json_decode((string) file_get_contents($root . '/schema/snapshot.v2.schema.json'));
    $encoded = json_decode(json_encode($data, JSON_THROW_ON_ERROR));
    $validator->validate($encoded, $schema);
    $this->assertTrue($validator->isValid(), json_encode($validator->getErrors(), JSON_THROW_ON_ERROR));
  }

  /**
   * Asserts that status_counts encodes as an object with these counts.
   *
   * @param mixed $snapshot
   *   The snapshot.
   * @param array<string, int> $nonZero
   *   The expected counts that are not zero.
   */
  protected function assertStatusCounts(mixed $snapshot, array $nonZero = []): void {
    $this->assertMatchesSchema($snapshot);
    $encoded = json_decode(json_encode($snapshot, JSON_THROW_ON_ERROR));
    $counts = $encoded->drupal->update_status->status_counts;
    $this->assertIsObject($counts);
    $this->assertSame(array_merge([
      'current' => 0,
      'update_available' => 0,
      'security_update_available' => 0,
      'not_supported' => 0,
      'revoked' => 0,
      'not_fetched' => 0,
      'unknown' => 0,
    ], $nonZero), (array) $counts);
  }

  /**
   * Stores update data with one project that needs a security update.
   */
  protected function seedUpdateData(): void {
    $now = \Drupal::time()->getRequestTime();
    \Drupal::state()->set('update.last_check', $now - 60);
    $store = \Drupal::keyValueExpirable('update_available_releases');
    $store->set('drupal', ['title' => 'Drupal core', 'last_fetch' => $now, 'releases' => []]);
    \Drupal::keyValueExpirable('update')
      ->set('update_project_data', ['drupal' => ['status' => UpdateManagerInterface::NOT_SECURE]]);
  }

}
