<?php

declare(strict_types=1);

namespace Drupal\Tests\project_context_connector\Functional;

use Drupal\project_context_connector\Service\RateLimiter;
use Drupal\Tests\BrowserTestBase;
use Symfony\Component\HttpFoundation\RequestStack;

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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Replace the rate limiter service with a test double that persists
    // request counts in the Symfony session so the limit is enforced
    // across multiple HTTP requests.
    $request_stack = $this->container->get('request_stack');
    $testing_limiter = new TestingRateLimiter($request_stack);
    $this->container->set('project_context_connector.rate_limiter', $testing_limiter);
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
    $this->drupalGet('/project-context-connector/snapshot?n=1');
    $this->assertSession()->statusCodeEquals(200);

    // Second request should be throttled by the testing limiter.
    $this->drupalGet('/project-context-connector/snapshot?n=2');
    $this->assertSession()->statusCodeEquals(429);
  }

}

/**
 * Test double for the RateLimiter that persists counts in the session.
 *
 * This class extends the real service type so dependency injection continues
 * to satisfy type-hints in other services (like the event subscriber).
 */
final class TestingRateLimiter extends RateLimiter {

  /**
   * Request stack used to access the session.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  private RequestStack $stack;

  /**
   * Construct the testing limiter with only the request stack.
   *
   * @param \Symfony\Component\HttpFoundation\RequestStack $stack
   *   The request stack.
   */
  public function __construct(RequestStack $stack) {
    // Do not call parent::__construct(); we are a test double.
    $this->stack = $stack;
  }

  /**
   * {@inheritdoc}
   */
  public function check(string $key): bool {
    $request = $this->stack->getCurrentRequest();
    // In BrowserTestBase, sessions are enabled after login.
    $session = $request->getSession();
    $countKey = "pcc_rl_{$key}";
    $count = (int) $session->get($countKey, 0);

    if ($count >= 1) {
      return FALSE;
    }

    $session->set($countKey, $count + 1);
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function retryAfterSeconds(): int {
    return 60;
  }

}
