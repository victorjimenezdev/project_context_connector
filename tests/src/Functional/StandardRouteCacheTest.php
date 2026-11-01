<?php

declare(strict_types=1);

namespace Drupal\Tests\project_context_connector\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\project_context_connector\Traits\SnapshotRequestTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests authentication, cache boundaries and exposure on the standard route.
 */
#[Group('project_context_connector')]
#[RunTestsInSeparateProcesses]
final class StandardRouteCacheTest extends BrowserTestBase {

  use SnapshotRequestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['basic_auth', 'page_cache', 'dynamic_page_cache', 'project_context_connector'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests that grant and revocation apply through cold and warm caches.
   */
  public function testAnonymousGrantAndRevocation(): void {
    $this->config('system.performance')->set('cache.page.max_age', 300)->save();
    $this->setCacheMarkers();

    // Cold and warm denial. Basic Auth challenges anonymous requests with 401.
    $this->assertSame(401, $this->send('GET', $this->standardPath)->getStatusCode());
    $this->assertSame(401, $this->send('GET', $this->standardPath)->getStatusCode());

    // A grant applies at once, then cold and warm requests stay uncached.
    $this->setSnapshotPermission('anonymous', TRUE);
    foreach (['cold', 'warm'] as $case) {
      $response = $this->send('GET', $this->standardPath);
      $this->assertSame(200, $response->getStatusCode(), $case);
      $this->assertNotSame('HIT', $response->getHeaderLine('X-Drupal-Cache'), $case);
      $this->assertNotSame('HIT', $response->getHeaderLine('X-Drupal-Dynamic-Cache'), $case);
      $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'), $case);
    }

    // A revocation applies to the next request through its normal tag
    // invalidation, while shared cache bins keep their other entries.
    $invalidations = $this->roleTagInvalidations('anonymous');
    $this->setSnapshotPermission('anonymous', FALSE);
    $this->assertGreaterThan($invalidations, $this->roleTagInvalidations('anonymous'));
    $this->assertCacheMarkersRemain();
    $response = $this->send('GET', $this->standardPath);
    $this->assertSame(401, $response->getStatusCode());
    $this->assertStringNotContainsString('active_modules', (string) $response->getBody());
    $this->assertCacheMarkersRemain();
  }

  /**
   * Tests Basic Auth, cookies, client max-age and exposure output.
   */
  public function testAuthenticationAndExposure(): void {
    $role = $this->drupalCreateRole(['access project context snapshot']);
    $account = $this->drupalCreateUser();
    $account->addRole($role);
    $account->save();
    $auth = ['auth' => [$account->getAccountName(), $account->passRaw]];

    $this->assertSame(200, $this->send('GET', $this->standardPath, [], $auth)->getStatusCode());
    $bad = $this->send('GET', $this->standardPath, [], ['auth' => [$account->getAccountName(), 'wrong']]);
    $this->assertContains($bad->getStatusCode(), [401, 403]);
    $this->assertStringNotContainsString('active_modules', (string) $bad->getBody());

    // Session cookie.
    $this->drupalLogin($account);
    $this->drupalGet($this->standardPath);
    $this->assertSession()->statusCodeEquals(200);
    $this->drupalLogout();

    // Safe profile on a fresh install, zero stays zero.
    $response = $this->send('GET', $this->standardPath, [], $auth);
    $data = $this->decode($response);
    $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
    $this->assertSame(0, $data['_meta']['cache']['max_age']);
    $this->assertSame(['driver' => NULL, 'version' => NULL], $data['drupal']['database']);
    $this->assertArrayNotHasKey('update_status', $data['drupal']);
    foreach ($data['drupal']['active_modules'] as $module) {
      $this->assertNull($module['path']);
      $this->assertSame('unknown', $module['security_status']);
    }

    // A private client copy with a configured age, and exposed data.
    $this->config('project_context_connector.settings')
      ->set('cache_max_age', 120)
      ->set('expose_database_version', TRUE)
      ->set('expose_paths', TRUE)
      ->save();
    $response = $this->send('GET', $this->standardPath, [], $auth);
    $data = $this->decode($response);
    $cacheControl = $response->getHeaderLine('Cache-Control');
    $this->assertStringContainsString('private', $cacheControl);
    $this->assertStringContainsString('max-age=120', $cacheControl);
    $this->assertStringNotContainsString('public', $cacheControl);
    $this->assertNotNull($data['drupal']['database']['driver']);
    $this->assertNotNull($data['drupal']['active_modules'][0]['path']);

    // A revoked user is denied on the next request despite that max-age.
    $this->setSnapshotPermission($role, FALSE);
    $this->assertSame(403, $this->send('GET', $this->standardPath, [], $auth)->getStatusCode());
  }

}
