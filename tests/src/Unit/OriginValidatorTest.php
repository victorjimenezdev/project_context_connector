<?php

declare(strict_types=1);

namespace Drupal\Tests\project_context_connector\Unit;

use Drupal\project_context_connector\Service\OriginValidator;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests origin parsing and matching.
 */
#[Group('project_context_connector')]
final class OriginValidatorTest extends UnitTestCase {

  /**
   * Tests allowed and rejected origins.
   */
  public function testOrigins(): void {
    $validator = new OriginValidator($this->getConfigFactoryStub([
      'project_context_connector.settings' => [
        'allowed_origins' => [
          'https://example.com',
          'https://*.example.org',
          '*.bare.example',
          'http://localhost:3000',
          'https://[::1]:8443',
          'not a pattern',
        ],
      ],
    ]));
    $allowed = [
      'https://example.com',
      'HTTPS://EXAMPLE.COM',
      'https://example.com:443',
      'https://a.example.org',
      'https://a.b.example.org',
      'https://x.bare.example',
      'http://localhost:3000',
      'https://[::1]:8443',
    ];
    foreach ($allowed as $origin) {
      $this->assertSame($origin, $validator->allowedOriginFor($this->request($origin)), $origin);
    }
    $rejected = [
      '',
      'null',
      'https://example.com/',
      'https://example.com:8443',
      'http://example.com',
      'https://evil-example.com',
      'https://example.com.evil.test',
      'https://user@example.com',
      'https://user:pw@example.com',
      'https://example.com?x=1',
      'https://example.com#f',
      'https://example.com/path',
      'https://example.org',
      'https://a.example.org:8443',
      'http://a.example.org',
      'https://.example.org',
      'https://a..example.org',
      'https://-a.example.org',
      'https://*.example.org',
      'http://localhost',
      'http://localhost:99999',
      'ftp://example.com',
      "https://example.com\n",
    ];
    foreach ($rejected as $origin) {
      $this->assertNull($validator->allowedOriginFor($this->request($origin)), $origin);
    }
  }

  /**
   * Tests pattern validation.
   */
  public function testPatterns(): void {
    foreach (['https://a.example', 'http://a.example:8080', 'https://*.a.example', '*.a.example', 'https://[::1]'] as $pattern) {
      $this->assertTrue(OriginValidator::isValidPattern($pattern), $pattern);
    }
    $invalid = [
      '*',
      'https://*',
      '*.com',
      'https://*.com',
      'a.example',
      'https://a.example/',
      'https://u@a.example',
      'https://a.example?q',
      'https://*.[::1]',
      'https://a.*.example',
    ];
    foreach ($invalid as $pattern) {
      $this->assertFalse(OriginValidator::isValidPattern($pattern), $pattern);
    }
  }

  /**
   * Builds a request with an Origin header.
   */
  private function request(string $origin): Request {
    $request = Request::create('/');
    $request->headers->set('Origin', $origin);
    return $request;
  }

}
