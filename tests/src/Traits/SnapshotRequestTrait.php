<?php

declare(strict_types=1);

namespace Drupal\Tests\project_context_connector\Traits;

use Drupal\Core\Cache\Cache;
use Drupal\user\Entity\Role;
use Psr\Http\Message\ResponseInterface;

/**
 * Sends real HTTP requests to the snapshot routes.
 */
trait SnapshotRequestTrait {

  /**
   * The signed route path.
   */
  protected string $signedPath = '/project-context-connector/snapshot/signed';

  /**
   * The standard route path.
   */
  protected string $standardPath = '/project-context-connector/snapshot';

  /**
   * Grants or revokes the snapshot permission on a role.
   *
   * Real admin requests each start with an empty list of invalidated tags. This
   * test process makes several saves, so it clears that in-memory list first.
   * Otherwise a second save skips the database invalidation of the role tag and
   * the server keeps using its cached permissions. No cache bin is changed.
   */
  protected function setSnapshotPermission(string $role, bool $granted): void {
    \Drupal::service('cache_tags.invalidator.checksum')->reset();
    $entity = Role::load($role);
    if ($granted) {
      $entity->grantPermission('access project context snapshot');
    }
    else {
      $entity->revokePermission('access project context snapshot');
    }
    $entity->save();
  }

  /**
   * Stores a marker with an unrelated tag in both enabled page cache bins.
   */
  protected function setCacheMarkers(): void {
    foreach (['page', 'dynamic_page_cache'] as $bin) {
      \Drupal::cache($bin)->set('pcc_test_marker', $bin, Cache::PERMANENT, ['pcc_test_marker']);
    }
  }

  /**
   * Asserts that no broad cache flush removed the markers.
   */
  protected function assertCacheMarkersRemain(): void {
    foreach (['page', 'dynamic_page_cache'] as $bin) {
      $marker = \Drupal::cache($bin)->get('pcc_test_marker');
      $this->assertNotFalse($marker, "The marker remains in the $bin bin.");
      $this->assertSame($bin, $marker->data);
    }
  }

  /**
   * Returns the stored invalidation count of a role's cache tag.
   */
  protected function roleTagInvalidations(string $role): int {
    return (int) \Drupal::database()->select('cachetags', 'c')
      ->fields('c', ['invalidations'])
      ->condition('tag', "config:user.role.$role")
      ->execute()->fetchField();
  }

  /**
   * Sends a request and returns the response without throwing on 4xx.
   *
   * @param string $method
   *   The HTTP method.
   * @param string $path
   *   The site-relative path.
   * @param array<string, string> $headers
   *   The request headers.
   * @param array<string, mixed> $options
   *   Further Guzzle options.
   */
  protected function send(string $method, string $path, array $headers = [], array $options = []): ResponseInterface {
    return $this->getHttpClient()->request($method, $this->baseUrl . $path, [
      'headers' => $headers,
      'http_errors' => FALSE,
      'allow_redirects' => FALSE,
    ] + $options);
  }

  /**
   * Returns signature headers for a path, exactly as the request sends it.
   *
   * @return array<string, string>
   *   The headers.
   */
  protected function signedHeaders(string $key, string $secret, ?int $timestamp = NULL, string $method = 'GET', ?string $path = NULL): array {
    $timestamp ??= time();
    return [
      'X-PCC-Key' => $key,
      'X-PCC-Timestamp' => (string) $timestamp,
      'X-PCC-Signature' => hash_hmac('sha256', $method . "\n" . ($path ?? $this->signedPath) . "\n" . $timestamp, $secret),
    ];
  }

  /**
   * Sets the HMAC keys in settings.php of the test site.
   *
   * @param array<string, string> $keys
   *   The secrets keyed by key ID.
   */
  protected function setApiKeys(array $keys): void {
    $this->writeSettings([
      'settings' => [
        'project_context_connector_api_keys' => (object) ['value' => $keys, 'required' => TRUE],
      ],
    ]);
  }

  /**
   * Decodes a JSON response.
   *
   * @return array<mixed>
   *   The decoded body.
   */
  protected function decode(ResponseInterface $response): array {
    return json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
  }

}
