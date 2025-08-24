<?php

declare(strict_types=1);

namespace Drupal\Tests\project_context_connector\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\project_context_connector\Service\RateLimiter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Unit tests for the RateLimiter service.
 *
 * @coversDefaultClass \Drupal\project_context_connector\Service\RateLimiter
 * @group project_context_connector
 */
final class RateLimiterTest extends TestCase {

  /**
   * Confirms check() allows until threshold and then blocks.
   *
   * This test verifies that:
   * - Two calls are allowed when threshold=2.
   * - The third call is blocked.
   * - The retryAfterSeconds() returns the configured window.
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

    $currentUser = $this->createMock(AccountProxyInterface::class);
    $currentUser->method('isAuthenticated')->willReturn(TRUE);
    $currentUser->method('id')->willReturn(1);

    // First two allowed, third blocked.
    $flood->expects(self::exactly(3))
      ->method('isAllowed')
      ->willReturnOnConsecutiveCalls(TRUE, TRUE, FALSE);

    // Flood registration should happen for the first two allowed calls.
    $flood->expects(self::exactly(2))
      ->method('register');

    $logger = $this->getMockBuilder(LoggerChannelInterface::class)->getMock();

    $limiter = new RateLimiter(
      $flood,
      $stack,
      $factory,
      $currentUser,
      $logger
    );

    // Request #1.
    $stack->push(new Request(server: ['REMOTE_ADDR' => '127.0.0.1']));
    self::assertTrue($limiter->check('snapshot'));
    $stack->pop();

    // Request #2.
    $stack->push(new Request(server: ['REMOTE_ADDR' => '127.0.0.1']));
    self::assertTrue($limiter->check('snapshot'));
    $stack->pop();

    // Request #3.
    $stack->push(new Request(server: ['REMOTE_ADDR' => '127.0.0.1']));
    self::assertFalse($limiter->check('snapshot'));
    $stack->pop();

    self::assertSame(60, $limiter->retryAfterSeconds());
  }

}
