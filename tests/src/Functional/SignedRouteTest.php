<?php

declare(strict_types=1);

namespace Drupal\Tests\project_context_connector\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\project_context_connector\Traits\SnapshotRequestTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the signed route through real HTTP requests and routing.
 *
 * Page Cache is enabled, so a cached response would be visible here.
 */
#[Group('project_context_connector')]
#[RunTestsInSeparateProcesses]
final class SignedRouteTest extends BrowserTestBase {

  use SnapshotRequestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['page_cache', 'dynamic_page_cache', 'project_context_connector'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests denial, success, key revocation and OPTIONS on the signed route.
   */
  public function testSignedAccess(): void {
    $this->setApiKeys(['bot' => 'test-secret-value']);
    $good = $this->signedHeaders('bot', 'test-secret-value');

    $denied = [
      'missing' => [],
      'unknown key' => $this->signedHeaders('nobody', 'test-secret-value'),
      'wrong secret' => $this->signedHeaders('bot', 'other-secret'),
      'expired' => $this->signedHeaders('bot', 'test-secret-value', time() - 301),
      'future' => $this->signedHeaders('bot', 'test-secret-value', time() + 360),
      'other method' => $this->signedHeaders('bot', 'test-secret-value', NULL, 'POST'),
    ];
    foreach ($denied as $case => $headers) {
      $response = $this->send('GET', $this->signedPath, $headers);
      $body = (string) $response->getBody();
      $this->assertSame(403, $response->getStatusCode(), $case);
      $this->assertStringNotContainsString('active_modules', $body, $case);
      $this->assertStringNotContainsString('test-secret-value', $body, $case);
    }

    // The earlier 403 responses for this URL must not be served from a cache.
    $response = $this->send('GET', $this->signedPath, $good);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));
    $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
    $this->assertStringContainsString('private', $response->getHeaderLine('Cache-Control'));
    $this->assertNotSame('HIT', $response->getHeaderLine('X-Drupal-Cache'));
    $data = $this->decode($response);
    $this->assertArrayHasKey('active_modules', $data['drupal']);
    $this->assertSame(0, $data['_meta']['cache']['max_age']);

    // The earlier 200 must not satisfy a later unsigned request.
    $response = $this->send('GET', $this->signedPath);
    $this->assertSame(403, $response->getStatusCode());
    $this->assertStringNotContainsString('active_modules', (string) $response->getBody());

    // Path variants that routing accepts must not reach a page cache.
    $upper = strtoupper($this->signedPath);
    $this->assertSame(403, $this->send('GET', $upper)->getStatusCode());
    $this->assertNotSame('HIT', $this->send('GET', $upper)->getHeaderLine('X-Drupal-Cache'));

    // OPTIONS never returns a snapshot, even with a valid signature.
    foreach ([[], $good, $good + ['Origin' => 'https://client.example']] as $headers) {
      $response = $this->send('OPTIONS', $this->signedPath, $headers);
      $this->assertStringNotContainsString('active_modules', (string) $response->getBody());
      $this->assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
    }

    // A removed key stops working on the next request.
    $this->setApiKeys(['other' => 'another-secret']);
    $this->assertSame(403, $this->send('GET', $this->signedPath, $this->signedHeaders('bot', 'test-secret-value'))->getStatusCode());
  }

}
