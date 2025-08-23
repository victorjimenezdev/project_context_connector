<?php

declare(strict_types=1);

namespace Drupal\Tests\project_context_connector\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\project_context_connector\Service\OriginValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 *
 */
final class OriginValidatorTest extends TestCase {

  /**
   *
   */
  public function testExactAndWildcard(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->with('allowed_origins')
      ->willReturn(['https://example.com', '*.example.org', 'https://sub.domain.com']);

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->with('project_context_connector.settings')->willReturn($config);

    $validator = new OriginValidator($factory);

    $req1 = new Request(server: ['HTTP_ORIGIN' => 'https://example.com']);
    self::assertSame('https://example.com', $validator->allowedOriginFor($req1));

    $req2 = new Request(server: ['HTTP_ORIGIN' => 'https://a.example.org']);
    self::assertSame('https://a.example.org', $validator->allowedOriginFor($req2));

    $req3 = new Request(server: ['HTTP_ORIGIN' => 'https://b.c.example.org']);
    self::assertSame('https://b.c.example.org', $validator->allowedOriginFor($req3));

    $req4 = new Request(server: ['HTTP_ORIGIN' => 'https://domain.com']);
    self::assertNull($validator->allowedOriginFor($req4));
  }

}
