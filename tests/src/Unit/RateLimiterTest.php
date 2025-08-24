<?php

declare(strict_types=1);

namespace Drupal\Tests\project_context_connector\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\project_context_connector\Service\RateLimiter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @coversDefaultClass \Drupal\project_context_connector\Service\RateLimiter
 * @group project_context_connector
 */
final class RateLimiterTest extends TestCase {

  /**
   * Ensures the limiter allows up to threshold and then blocks with retry info.
   *
   * @covers ::check
   * @covers ::retryAfterSeconds
   */
  public function testAllowedAndBlocked(): void {
    $flood = $this->createMock(FloodInterface::class);

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnMap([
      ['rate_limit_threshold', 2],
      ['rate_limit_window', 60],
    ]);

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')
      ->with('project_context_connector.settings')
      ->willReturn($config);

    $stack = new RequestStack();
    $stack->push(new Request(server: ['REMOTE_ADDR' => '127.0.0.1']));

    // First two allowed, third blocked.
    $flood->expects(self::exactly(3))
      ->method('isAllowed')
      ->willReturnOnConsecutiveCalls(TRUE, TRUE, FALSE);
    $flood->expects(self::exactly(2))
      ->method('register');

    $limiter = new RateLimiter(
      $flood,
      $stack,
      $factory,
      $this->getMockBuilder(LoggerChannelInterface::class)->getMock()
    );

    self::assertTrue($limiter->check('snapshot'));
    self::assertTrue($limiter->check('snapshot'));
    self::assertFalse($limiter->check('snapshot'));
    self::assertSame(60, $limiter->retryAfterSeconds());
  }

}
