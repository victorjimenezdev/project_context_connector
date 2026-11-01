<?php

declare(strict_types=1);

namespace Drupal\Tests\project_context_connector\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\project_context_connector\Traits\SnapshotRequestTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests CORS through real HTTP requests.
 */
#[Group('project_context_connector')]
#[RunTestsInSeparateProcesses]
final class CorsTest extends BrowserTestBase {

  use SnapshotRequestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['basic_auth', 'project_context_connector'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests fresh defaults, preflight, actual requests and Vary.
   */
  public function testCors(): void {
    $preflight = [
      'Origin' => 'https://app.example.com',
      'Access-Control-Request-Method' => 'GET',
      'Access-Control-Request-Headers' => 'authorization',
    ];

    // Fresh install: disabled, no origins, and OPTIONS holds no snapshot.
    $config = $this->config('project_context_connector.settings');
    $this->assertFalse($config->get('enable_cors'));
    $this->assertSame([], $config->get('allowed_origins'));
    $response = $this->send('OPTIONS', $this->standardPath, $preflight);
    $this->assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
    $this->assertStringNotContainsString('active_modules', (string) $response->getBody());

    $config->set('enable_cors', TRUE)->set('allowed_origins', ['https://app.example.com', 'https://*.example.org:8443'])->save();

    // An allowed preflight needs no credentials and returns no payload.
    $response = $this->send('OPTIONS', $this->standardPath, $preflight);
    $this->assertSame(204, $response->getStatusCode());
    $this->assertSame('https://app.example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
    $this->assertSame('GET', $response->getHeaderLine('Access-Control-Allow-Methods'));
    $this->assertSame('', (string) $response->getBody());
    $this->assertFalse($response->hasHeader('Access-Control-Allow-Credentials'));

    // Rejected preflights.
    $rejected = [
      ['Origin' => 'https://evil.example.com'] + $preflight,
      ['Origin' => 'https://app.example.com:8443'] + $preflight,
      ['Origin' => 'http://app.example.com'] + $preflight,
      ['Origin' => 'https://user@app.example.com'] + $preflight,
      ['Origin' => 'null'] + $preflight,
      ['Access-Control-Request-Method' => 'POST'] + $preflight,
      ['Access-Control-Request-Headers' => 'x-unlisted'] + $preflight,
    ];
    foreach ($rejected as $headers) {
      $response = $this->send('OPTIONS', $this->standardPath, $headers);
      $this->assertSame(403, $response->getStatusCode(), $headers['Origin']);
      $this->assertFalse($response->hasHeader('Access-Control-Allow-Origin'), $headers['Origin']);
      $this->assertSame('', (string) $response->getBody());
    }

    // The wildcard with its port.
    $response = $this->send('OPTIONS', $this->standardPath, ['Origin' => 'https://a.example.org:8443'] + $preflight);
    $this->assertSame(204, $response->getStatusCode());

    // A plain OPTIONS without preflight headers holds no snapshot either.
    $this->assertStringNotContainsString('active_modules', (string) $this->send('OPTIONS', $this->standardPath)->getBody());

    // Actual request: the header follows the allow-list and Vary stays whole.
    $account = $this->drupalCreateUser(['access project context snapshot']);
    $auth = ['auth' => [$account->getAccountName(), $account->passRaw]];
    $response = $this->send('GET', $this->standardPath, ['Origin' => 'https://app.example.com'], $auth);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('https://app.example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
    $vary = array_map('trim', explode(',', implode(',', $response->getHeader('Vary'))));
    foreach (['Origin', 'Authorization', 'Cookie'] as $expected) {
      $this->assertContains($expected, $vary);
    }
    $response = $this->send('GET', $this->standardPath, ['Origin' => 'https://evil.example.com'], $auth);
    $this->assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
    $this->assertStringContainsString('Origin', implode(',', $response->getHeader('Vary')));

    // The signed route never gets CORS headers.
    $response = $this->send('GET', $this->signedPath, ['Origin' => 'https://app.example.com']);
    $this->assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
  }

}
