<?php

declare(strict_types=1);

namespace Drupal\Tests\project_context_connector\Functional;

use Drupal\Core\Flood\FloodDatabaseBackend;
use Drupal\Tests\BrowserTestBase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Functional tests for the snapshot endpoint.
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
   * Use Stark to minimize dependencies.
   *
   * @var string
   */
  protected $defaultTheme = 'stark';

  /**
   * Override the testing container: use DB-backed Flood so counts persist.
   *
   * The default testing container uses FloodMemoryBackend, which does not
   * persist across real HTTP requests. Using FloodDatabaseBackend makes the
   * second request in this test see the first request's registration.
   */
  protected function containerBuild(ContainerBuilder $container): void {
    parent::containerBuild($container);

    $def = $container->getDefinition('flood');
    $def->setClass(FloodDatabaseBackend::class);
    $def->setArguments([
      new Reference('database'),
      new Reference('request_stack'),
      new Reference('datetime.time'),
    ]);
  }

  /**
   * Ensure anonymous users without the permission get 403.
   */
  public function testSnapshotForbiddenForAnonymous(): void {
    $this->drupalGet('/project-context-connector/snapshot');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Ensure an allowed user can fetch JSON and the response looks correct.
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

    // First request should be allowed.
    $this->drupalGet('/project-context-connector/snapshot');
    $this->assertSession()->statusCodeEquals(200);

    // Second request to the same route should now be throttled (per-uid).
    $this->drupalGet('/project-context-connector/snapshot');
    $this->assertSession()->statusCodeEquals(429);
  }

}
