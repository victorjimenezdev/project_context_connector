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

  /**
   * Modules to enable for this test.
   *
   * @var string[]
   */
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
    $path = '/project-context-connector/snapshot';

    $this->drupalGet($path);
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Ensure an allowed user can fetch JSON.
   */
  public function testSnapshotSuccessAndJsonShape(): void {
    $path = '/project-context-connector/snapshot';

    $account = $this->drupalCreateUser([
      'access project context snapshot',
    ]);
    $this->drupalLogin($account);

    // Cache-busting query illustrates typical client usage without
    // affecting the payload.
    $this->drupalGet(
      $path,
      ['query' => ['_r' => 'ok']]
    );
    $this->assertSession()->statusCodeEquals(200);

    $content_type = (string) $this->getSession()
      ->getResponseHeader('content-type');
    $this->assertStringStartsWith('application/json', $content_type);

    $json = json_decode(
      $this->getSession()->getPage()->getContent(),
      TRUE,
      512,
      JSON_THROW_ON_ERROR
    );
    $this->assertIsArray($json);
    $this->assertArrayHasKey('drupal', $json);
    $this->assertArrayHasKey('active_modules', $json['drupal']);
  }

  /**
   * Verify basic throttling.
   *
   * With threshold set to 1, the first request should pass (200) and the
   * second request within the window should be throttled (429).
   *
   * We use two different query strings so each request is uncached and
   * reaches the throttle subscriber.
   */
  public function testRateLimit(): void {
    $path = '/project-context-connector/snapshot';

    $this->config('project_context_connector.settings')
      ->set('rate_limit_threshold', 1)
      ->set('rate_limit_window', 60)
      ->save();

    $account = $this->drupalCreateUser([
      'access project context snapshot',
    ]);
    $this->drupalLogin($account);

    // First request: fresh URL, should register with limiter and pass.
    $this->drupalGet(
      $path,
      ['query' => ['_r' => 'a']]
    );
    $this->assertSession()->statusCodeEquals(200);

    // Second request: different query to avoid page cache; should be
    // throttled by the limiter.
    $this->drupalGet(
      $path,
      ['query' => ['_r' => 'b']]
    );
    $this->assertSession()->statusCodeEquals(429);
  }

}
