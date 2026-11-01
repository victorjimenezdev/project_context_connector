<?php

declare(strict_types=1);

namespace Drupal\Tests\project_context_connector\Functional;

use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\path_alias\Entity\PathAlias;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\project_context_connector\Traits\SnapshotRequestTrait;
use Drupal\Tests\project_context_connector\Traits\SnapshotSchemaTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Http\Message\ResponseInterface;

/**
 * Tests path variants that Drupal resolves to the snapshot routes.
 *
 * Requests use real HTTP, routing, Flood storage and page caches.
 */
#[Group('project_context_connector')]
#[RunTestsInSeparateProcesses]
final class PathVariantTest extends BrowserTestBase {

  use SnapshotRequestTrait;
  use SnapshotSchemaTrait;

  private const array SIGNED_VARIANTS = [
    '/project-context-connector/snapshot/signed',
    '/project-context-connector/%73napshot/signed',
    '/project%2Dcontext%2Dconnector/SNAPSHOT/%73igned',
    '/es/project-context-connector/snapshot/signed',
    '/es/project-context-connector/%73napshot/signed',
    '/pcc-signed-alias',
  ];

  private const array STANDARD_VARIANTS = [
    '/project-context-connector/snapshot',
    '/project-context-connector/%73napshot',
    '/es/project-context-connector/%73napshot',
    '/pcc-standard-alias',
  ];

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'basic_auth',
    'dynamic_page_cache',
    'language',
    'page_cache',
    'path_alias',
    'project_context_connector',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->setApiKeys(['bot' => 'test-secret-value', 'other' => 'other-secret-value']);
    ConfigurableLanguage::createFromLangcode('es')->save();
    $this->config('language.types')
      ->set('negotiation.language_interface.enabled', ['language-url' => 0, 'language-selected' => 1])
      ->save();
    PathAlias::create(['path' => $this->signedPath, 'alias' => '/pcc-signed-alias', 'langcode' => 'und'])->save();
    PathAlias::create(['path' => $this->standardPath, 'alias' => '/pcc-standard-alias', 'langcode' => 'und'])->save();
    $this->rebuildAll();
  }

  /**
   * Returns the number of Flood rows for an event.
   */
  private function floodRows(string $event): int {
    return (int) \Drupal::database()->select('flood', 'f')
      ->condition('event', $event)
      ->countQuery()->execute()->fetchField();
  }

  /**
   * Asserts a 429 response that holds no snapshot.
   */
  private function assertLimited(ResponseInterface $response, string $retryAfter, string $case): void {
    $this->assertSame(429, $response->getStatusCode(), $case);
    $this->assertSame($retryAfter, $response->getHeaderLine('Retry-After'), $case);
    $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'), $case);
    $this->assertStringNotContainsString('active_modules', (string) $response->getBody(), $case);
  }

  /**
   * Tests canonical signing, denial and valid requests on each path variant.
   */
  public function testSignedVariants(): void {
    foreach (self::SIGNED_VARIANTS as $path) {
      $headers = $this->signedHeaders('bot', 'test-secret-value', NULL, 'GET', $path);
      $response = $this->send('GET', $path, $headers);
      $this->assertSame(200, $response->getStatusCode(), $path);
      $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'), $path);
      $this->assertNotSame('HIT', $response->getHeaderLine('X-Drupal-Cache'), $path);
      $this->assertArrayHasKey('active_modules', $this->decode($response)['drupal'], $path);

      // The earlier 200 must not satisfy an unsigned request.
      $response = $this->send('GET', $path);
      $this->assertSame(403, $response->getStatusCode(), $path);
      $this->assertStringNotContainsString('active_modules', (string) $response->getBody(), $path);
    }

    // PATH is signed as sent. A signature of another spelling is denied.
    foreach (self::SIGNED_VARIANTS as $path) {
      if ($path !== $this->signedPath) {
        $response = $this->send('GET', $path, $this->signedHeaders('bot', 'test-secret-value'));
        $this->assertSame(403, $response->getStatusCode(), $path);
        $this->assertStringNotContainsString('active_modules', (string) $response->getBody(), $path);
      }
    }
    $encoded = '/project-context-connector/%73napshot/signed';
    $response = $this->send('GET', $this->signedPath, $this->signedHeaders('bot', 'test-secret-value', NULL, 'GET', $encoded));
    $this->assertSame(403, $response->getStatusCode());

    // Core decodes once, so a double-encoded path is not the route.
    $double = '/project-context-connector/%2573napshot/signed';
    $response = $this->send('GET', $double, $this->signedHeaders('bot', 'test-secret-value', NULL, 'GET', $double));
    $this->assertSame(404, $response->getStatusCode());
    $this->assertStringNotContainsString('active_modules', (string) $response->getBody());
  }

  /**
   * Tests that a blocked client gets 429 on every variant, with no more writes.
   */
  public function testExhaustedFailedAuthorization(): void {
    $this->config('project_context_connector.settings')
      ->set('failed_auth_threshold', 2)
      ->set('failed_auth_window', 120)
      ->save();

    // Failures through encoded paths count against the limit.
    $encoded = '/project-context-connector/%73napshot/signed';
    $this->assertSame(403, $this->send('GET', $encoded)->getStatusCode());
    $this->assertSame(403, $this->send('GET', $encoded)->getStatusCode());
    $rows = $this->floodRows('pcc.auth_failure');
    $this->assertSame(4, $rows);

    foreach (array_merge(self::SIGNED_VARIANTS, self::STANDARD_VARIANTS) as $path) {
      foreach (['GET', 'HEAD'] as $method) {
        $this->assertLimited($this->send($method, $path), '120', "$method $path");
      }
    }
    $this->assertSame($rows, $this->floodRows('pcc.auth_failure'));

    // The block runs before the signature check, so a correct signature for a
    // blocked key also gets 429 and uses no quota.
    $bad = $this->signedHeaders('bot', 'wrong-secret', NULL, 'GET', $encoded);
    $this->assertSame(403, $this->send('GET', $encoded, $bad)->getStatusCode());
    $this->assertSame(403, $this->send('GET', $encoded, $bad)->getStatusCode());
    $rows = $this->floodRows('pcc.auth_failure');
    $good = $this->signedHeaders('bot', 'test-secret-value', NULL, 'GET', $encoded);
    $this->assertLimited($this->send('GET', $encoded, $good), '120', 'blocked key');
    $this->assertSame($rows, $this->floodRows('pcc.auth_failure'));
    $this->assertSame(0, $this->floodRows('pcc.snapshot'));
  }

  /**
   * Tests the shared quota across path variants of both routes.
   */
  public function testExhaustedQuota(): void {
    $this->config('project_context_connector.settings')
      ->set('rate_limit_threshold', count(self::SIGNED_VARIANTS))
      ->set('rate_limit_window', 120)
      ->save();

    foreach (self::SIGNED_VARIANTS as $path) {
      $response = $this->send('GET', $path, $this->signedHeaders('bot', 'test-secret-value', NULL, 'GET', $path));
      $this->assertSame(200, $response->getStatusCode(), $path);
    }
    $rows = $this->floodRows('pcc.snapshot');
    $this->assertSame(2 * count(self::SIGNED_VARIANTS), $rows);

    foreach (self::SIGNED_VARIANTS as $path) {
      foreach (['GET', 'HEAD'] as $method) {
        $response = $this->send($method, $path, $this->signedHeaders('other', 'other-secret-value', NULL, $method, $path));
        $this->assertLimited($response, '120', "$method $path");
      }
    }
    $this->assertSame($rows, $this->floodRows('pcc.snapshot'));
    $this->assertSame(0, $this->floodRows('pcc.auth_failure'));
  }

  /**
   * Tests that page caches do not store a variant of the standard route.
   */
  public function testCacheOnStandardVariants(): void {
    $this->setCacheMarkers();
    $this->setSnapshotPermission('anonymous', TRUE);
    foreach (self::STANDARD_VARIANTS as $path) {
      $this->assertSame(200, $this->send('GET', $path)->getStatusCode(), $path);
      $response = $this->send('GET', $path);
      $this->assertSame(200, $response->getStatusCode(), $path);
      $this->assertNotSame('HIT', $response->getHeaderLine('X-Drupal-Cache'), $path);
    }
    // Revocation uses normal tag invalidation and leaves shared bins in place.
    $invalidations = $this->roleTagInvalidations('anonymous');
    $this->setSnapshotPermission('anonymous', FALSE);
    $this->assertGreaterThan($invalidations, $this->roleTagInvalidations('anonymous'));
    $this->assertCacheMarkersRemain();
    foreach (self::STANDARD_VARIANTS as $path) {
      $response = $this->send('GET', $path);
      $this->assertContains($response->getStatusCode(), [401, 403], $path);
      $this->assertStringNotContainsString('active_modules', (string) $response->getBody(), $path);
    }
    $this->assertCacheMarkersRemain();
  }

  /**
   * Tests status_counts over HTTP when update data is unavailable and present.
   */
  public function testStatusCountsOverHttp(): void {
    $this->config('project_context_connector.settings')->set('expose_update_status', TRUE)->save();
    $this->setSnapshotPermission('anonymous', TRUE);

    $unavailable = $this->decode($this->send('GET', $this->standardPath));
    $this->assertSame('unavailable', $unavailable['drupal']['update_status']['data_state']);
    $this->assertStatusCounts($unavailable);

    \Drupal::service('module_installer')->install(['update']);
    $this->rebuildAll();
    $missing = $this->decode($this->send('GET', $this->standardPath));
    $this->assertSame('missing', $missing['drupal']['update_status']['data_state']);
    $this->assertStatusCounts($missing);

    $this->seedUpdateData();
    $populated = $this->decode($this->send('GET', $this->standardPath));
    $this->assertSame('current', $populated['drupal']['update_status']['data_state']);
    $this->assertStatusCounts($populated, ['security_update_available' => 1]);

    // The raw bytes hold an object, not a list.
    $body = (string) $this->send('GET', $this->standardPath)->getBody();
    $this->assertStringContainsString('"status_counts":{"current":0,', $body);
  }

}
