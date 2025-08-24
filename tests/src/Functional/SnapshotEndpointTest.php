<?php

declare(strict_types=1);

namespace Drupal\Tests\project_context_connector\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Functional tests for the snapshot endpoints.
 *
 * @group project_context_connector
 */
final class SnapshotEndpointTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'project_context_connector',
  ];

  /**
   * Ensure anonymous users without the permission get 403.
   */
  public function testSnapshotForbiddenForAnonymous(): void {
    $this->drupalGet('/project-context-connector/snapshot');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Ensure an allowed user can fetch JSON and that the response looks correct.
   */
  public function testSnapshotSuccessAndJsonShape(): void {
    $account = $this->drupalCreateUser(['access project context snapshot']);
    $this->drupalLogin($account);

    $this->drupalGet('/project-context-connector/snapshot');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderContains('content-type', 'application/json');

    $json = json_decode((string) $this->getSession()->getPage()->getContent(), TRUE);
    $this->assertIsArray($json);
    $this->assertArrayHasKey('drupal', $json);
    $this->assertIsArray($json['drupal']);
    $this->assertArrayHasKey('core_version', $json['drupal']);
  }

  /**
   * Verify basic throttling (429 on second hit with threshold=1).
   */
  public function testRateLimit(): void {
    // Configure an aggressive threshold for the test.
    $this->config('project_context_connector.settings')
      ->set('rate_limit.threshold', 1)
      ->set('rate_limit.window', 60)
      ->save();

    $user = $this->drupalCreateUser(['access project context snapshot']);
    $this->drupalLogin($user);

    // First request should pass.
    $this->drupalGet('/project-context-connector/snapshot');
    $this->assertSession()->statusCodeEquals(200);

    // Second request in the same window should be throttled.
    $this->drupalGet('/project-context-connector/snapshot');
    $this->assertSession()->statusCodeEquals(429);
    $this->assertSession()->responseHeaderExists('retry-after');
  }

}
