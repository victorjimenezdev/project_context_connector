<?php

declare(strict_types=1);

namespace Drupal\Tests\project_context_connector\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests quota pools across IP addresses, which HTTP tests cannot vary.
 *
 * This uses the Flood backend of the kernel test container. ThrottleTest and
 * PathVariantTest cover the same rules with database Flood storage.
 */
#[Group('project_context_connector')]
#[RunTestsInSeparateProcesses]
final class RateLimiterKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'project_context_connector'];

  /**
   * Returns the number of units registered for an identifier, up to three.
   */
  private function units(string $identifier): int {
    $flood = $this->container->get('flood');
    for ($units = 0; $units < 3; $units++) {
      if ($flood->isAllowed('pcc.snapshot', $units + 1, 60, $identifier)) {
        return $units;
      }
    }
    return $units;
  }

  /**
   * Tests one unit per pool, and no units when any pool is exhausted.
   */
  public function testQuotaPools(): void {
    $this->installConfig(['project_context_connector']);
    $this->config('project_context_connector.settings')
      ->set('rate_limit_threshold', 2)
      ->set('rate_limit_window', 60)
      ->save();
    $limiter = $this->container->get('project_context_connector.rate_limiter');

    // A repeated identity counts once.
    $this->assertTrue($limiter->consumeQuota('ip:a', 'ip:a'));
    $this->assertSame(1, $this->units('ip:a'));

    // Each applicable pool counts once.
    $this->assertTrue($limiter->consumeQuota('ip:a', 'key:x'));
    $this->assertSame(2, $this->units('ip:a'));
    $this->assertSame(1, $this->units('key:x'));

    // An exhausted IP pool rejects a new credential, and it uses no unit.
    $this->assertFalse($limiter->consumeQuota('ip:a', 'key:y'));
    $this->assertSame(2, $this->units('ip:a'));
    $this->assertSame(0, $this->units('key:y'));

    // A credential cannot exceed the threshold from several addresses.
    $this->assertTrue($limiter->consumeQuota('ip:b', 'key:x'));
    $this->assertSame(2, $this->units('key:x'));
    $this->assertFalse($limiter->consumeQuota('ip:c', 'key:x'));
    $this->assertSame(0, $this->units('ip:c'));
    $this->assertSame(2, $this->units('key:x'));
  }

}
