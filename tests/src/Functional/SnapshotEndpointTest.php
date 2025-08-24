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
   * Minimal theme to avoid extra dependencies.
   *
   * @var string
   */
  protected $defaultTheme = 'stark';

  /**
   * Configure rate limiting so the second request in a window is throttled.
   *
   * We use the real RateLimiter service with controlled config rather than
   * trying to override the service in a browser test.
   */
  protected function setUp(): void {
    parent::setUp();

    // Check if flood table exists before trying to clear it.
    $database = $this->container->get('database');
    $schema = $database->schema();
    if ($schema->tableExists('flood')) {
      $database->truncate('flood')->execute();
    }

    // Make the limiter allow one request per 60 seconds for easy assertions.
    $this->config('project_context_connector.settings')
      ->set('rate_limit_threshold', 1)
      ->set('rate_limit_window', 60)
      ->save();
  }

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
    $account = $this->drupalCreateUser([
      'access project context snapshot',
    ]);
    $this->drupalLogin($account);

    $this->drupalGet('/project-context-connector/snapshot');
    $this->assertSession()->statusCodeEquals(200);

    $contentType = (string) $this->getSession()
      ->getResponseHeader('content-type');
    $this->assertStringStartsWith('application/json', $contentType);

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
   * Verify throttling returns 429 on the second request within the window.
   */
  public function testRateLimit(): void {
    $account = $this->drupalCreateUser([
      'access project context snapshot',
    ]);
    $this->drupalLogin($account);

    // First request should be allowed.
    $this->drupalGet('project-context-connector/snapshot', ['query' => ['n' => 1]]);
    $this->assertSession()->statusCodeEquals(200);

    // Find the *actual* flood event name recorded for this route + user.
    $uid = (string) $account->id();
    $connection = $this->container->get('database');
    $event = $connection->select('flood', 'f')
      ->fields('f', ['event'])
      ->condition('identifier', 'uid:' . $uid)
      ->condition('event', 'pcc.project_context_connector.snapshot')
      ->orderBy('timestamp', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchField();

    // If this fails, the endpoint did not register any flood event. That means.
    $this->assertNotEmpty($event, 'No flood event recorded for the snapshot endpoint; is RateLimiter->check() called?');

    // Pre-register once more so threshold=1 is exceeded.
    /** @var \Drupal\Core\Flood\FloodInterface $flood */
    $flood = $this->container->get('flood');
    $flood->register($event, 60, 'uid:' . $uid);

    // Second request should now be throttled.
    $this->drupalGet('project-context-connector/snapshot', ['query' => ['n' => 2]]);
    $this->assertSession()->statusCodeEquals(429);
  }

}
