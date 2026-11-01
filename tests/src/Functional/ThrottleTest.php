<?php

declare(strict_types=1);

namespace Drupal\Tests\project_context_connector\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\project_context_connector\Traits\SnapshotRequestTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests quota and failed-authorization limits with real Flood storage.
 */
#[Group('project_context_connector')]
#[RunTestsInSeparateProcesses]
final class ThrottleTest extends BrowserTestBase {

  use SnapshotRequestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['basic_auth', 'page_cache', 'project_context_connector'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests the shared quota across both routes and client identities.
   */
  public function testSharedQuota(): void {
    $this->setApiKeys(['bot' => 'test-secret-value', 'other' => 'other-secret-value', 'third' => 'third-secret-value']);
    $this->config('project_context_connector.settings')
      ->set('rate_limit_threshold', 4)
      ->set('rate_limit_window', 120)
      ->set('enable_cors', TRUE)
      ->set('allowed_origins', ['https://app.example.com'])
      ->save();

    $signed = fn(string $key, string $secret) => $this->send('GET', $this->signedPath, $this->signedHeaders($key, $secret));
    $account = $this->drupalCreateUser(['access project context snapshot']);
    $other = $this->drupalCreateUser(['access project context snapshot']);
    $auth = ['auth' => [$account->getAccountName(), $account->passRaw]];
    $standard = fn(array $options) => $this->send('GET', $this->standardPath, [], $options);
    $rows = fn(string $like) => (int) \Drupal::database()->select('flood', 'f')
      ->condition('event', 'pcc.snapshot')
      ->condition('identifier', $like, 'LIKE')
      ->countQuery()->execute()->fetchField();

    // Interleaved requests with two keys and one user share the IP allowance.
    $this->assertSame(200, $signed('bot', 'test-secret-value')->getStatusCode());
    $this->assertSame(200, $standard($auth)->getStatusCode());
    $this->assertSame(200, $signed('other', 'other-secret-value')->getStatusCode());
    $this->assertSame(200, $standard($auth)->getStatusCode());
    $this->assertSame(4, $rows('ip:%'));
    $this->assertSame(2, $rows('key:%'));
    $this->assertSame(2, $rows('uid:%'));

    // No credential or route change adds requests, and rejections use no units.
    $attempts = [
      'same key' => $signed('bot', 'test-secret-value'),
      'new key' => $signed('third', 'third-secret-value'),
      'same user' => $standard($auth),
      'new user' => $standard(['auth' => [$other->getAccountName(), $other->passRaw]]),
    ];
    foreach ($attempts as $case => $limited) {
      $this->assertSame(429, $limited->getStatusCode(), $case);
      $this->assertSame('120', $limited->getHeaderLine('Retry-After'), $case);
      $this->assertStringNotContainsString('active_modules', (string) $limited->getBody(), $case);
    }
    $this->assertSame(4, $rows('ip:%'));
    $this->assertSame(2, $rows('key:%'));
    $this->assertSame(2, $rows('uid:%'));

    // Quota rejections are not failed authorization.
    $failures = \Drupal::database()->select('flood', 'f')->condition('event', 'pcc.auth_failure')->countQuery()->execute()->fetchField();
    $this->assertSame(0, (int) $failures);

    // The flood records hold no key ID or secret.
    $identifiers = \Drupal::database()->select('flood', 'f')->fields('f', ['identifier'])->execute()->fetchCol();
    $this->assertNotEmpty($identifiers);
    foreach ($identifiers as $identifier) {
      $this->assertStringNotContainsString('bot', $identifier);
      $this->assertStringNotContainsString('secret', $identifier);
    }

    // Preflight uses no quota.
    for ($i = 0; $i < 3; $i++) {
      $response = $this->send('OPTIONS', $this->standardPath, [
        'Origin' => 'https://app.example.com',
        'Access-Control-Request-Method' => 'GET',
      ]);
      $this->assertSame(204, $response->getStatusCode());
    }

    // The anonymous role counts per IP, also through a warm page cache.
    \Drupal::database()->delete('flood')->execute();
    $this->config('project_context_connector.settings')->set('rate_limit_threshold', 2)->save();
    $this->setSnapshotPermission('anonymous', TRUE);
    $this->assertSame(200, $this->send('GET', $this->standardPath)->getStatusCode());
    $this->assertSame(200, $this->send('GET', $this->standardPath)->getStatusCode());
    $this->assertSame(429, $this->send('GET', $this->standardPath)->getStatusCode());
  }

  /**
   * Tests the failed-authorization limit and its shared IP allowance.
   */
  public function testFailedAuthorizationLimit(): void {
    $this->setApiKeys(['bot' => 'test-secret-value']);
    $this->config('project_context_connector.settings')
      ->set('failed_auth_threshold', 2)
      ->set('failed_auth_window', 120)
      ->save();

    $bad = $this->signedHeaders('attacker', 'wrong');
    $this->assertSame(403, $this->send('GET', $this->signedPath, $bad)->getStatusCode());
    $this->assertSame(403, $this->send('GET', $this->signedPath, $bad)->getStatusCode());
    $blocked = $this->send('GET', $this->signedPath, $bad);
    $this->assertSame(429, $blocked->getStatusCode());
    $this->assertSame('120', $blocked->getHeaderLine('Retry-After'));

    // A valid client on the same IP is unaffected below the IP limit.
    $this->assertSame(200, $this->send('GET', $this->signedPath, $this->signedHeaders('bot', 'test-secret-value'))->getStatusCode());

    // The IP limit is five times the threshold. Two failures are recorded so
    // far. Eight more, using new claimed keys, reach it.
    for ($i = 0; $i < 8; $i++) {
      $this->assertSame(403, $this->send('GET', $this->signedPath, $this->signedHeaders("k$i", 'wrong'))->getStatusCode());
    }
    $this->assertSame(429, $this->send('GET', $this->signedPath, $this->signedHeaders('bot', 'test-secret-value'))->getStatusCode());
    $this->assertSame(429, $this->send('GET', $this->standardPath)->getStatusCode());
  }

}
