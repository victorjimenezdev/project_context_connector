<?php

declare(strict_types=1);

namespace Drupal\Tests\project_context_connector\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Functional tests for the snapshot endpoint.
 *
 * @group project_context_connector
 */
final class SnapshotEndpointTest extends BrowserTestBase {

  protected static $modules = [
    'system',
    'user',
    'project_context_connector',
  ];

  /**
   * Use Stark to minimize dependencies.
   *
   * @var string
   */
  protected $defaultTheme = 'stark';

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

    $contentType = (string) $this->getSession()->getResponseHeader('content-type');
    $this->assertStringStartsWith('application/json', $contentType);

    $json = json_decode($this->getSession()->getPage()->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertIsArray($json);
    $this->assertArrayHasKey('drupal', $json);
    $this->assertArrayHasKey('active_modules', $json['drupal']);
  }

  /**
   * Verify basic throttling (429 on second hit with threshold=1).
   */
  public function testRateLimit(): void {
    $this->config('project_context_connector.settings')
      ->set('rate_limit_threshold', 1)
      ->set('rate_limit_window', 60)
      ->save();

    $account = $this->drupalCreateUser(['access project context snapshot']);
    $this->drupalLogin($account);

    $this->drupalGet('/project-context-connector/snapshot');
    $this->assertSession()->statusCodeEquals(200);

    // Second request within the window should return 429.
    $this->drupalGet('/project-context-connector/snapshot');
    $this->assertSession()->statusCodeEquals(429);
  }

}
