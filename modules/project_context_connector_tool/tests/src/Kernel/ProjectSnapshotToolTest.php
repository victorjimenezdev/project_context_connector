<?php

declare(strict_types=1);

namespace Drupal\Tests\project_context_connector_tool\Kernel;

use Composer\InstalledVersions;
use Drupal\KernelTests\KernelTestBase;
use Drupal\project_context_connector\Service\ContextSnapshotter;
use Drupal\Tests\project_context_connector\Traits\SnapshotSchemaTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests discovery, access and output of the Tool API adapter.
 */
#[Group('project_context_connector')]
#[RunTestsInSeparateProcesses]
final class ProjectSnapshotToolTest extends KernelTestBase {

  use SnapshotSchemaTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'tool', 'project_context_connector', 'project_context_connector_tool'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    if (!InstalledVersions::isInstalled('drupal/tool')) {
      $this->markTestSkipped('drupal/tool is not installed. It is optional, and it does not yet declare Drupal 12 support.');
    }
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['system', 'project_context_connector']);
    $this->config('project_context_connector.settings')->set('expose_update_status', TRUE)->save();
  }

  /**
   * Creates the tool.
   */
  private function tool(): object {
    return $this->container->get('plugin.manager.tool')->createInstance('project_context_snapshot');
  }

  /**
   * Tests that the tool has the snapshot permission for the acting account.
   */
  public function testAccess(): void {
    // User 1 bypasses permission checks, so use it up first.
    $this->createUser([]);
    $denied = $this->createUser([]);
    $allowed = $this->createUser(['access project context snapshot']);
    $this->assertFalse($this->tool()->access($denied));
    $this->assertTrue($this->tool()->access($allowed));
  }

  /**
   * Tests structured output and the include_security_updates input.
   */
  public function testOutput(): void {
    $this->setUpCurrentUser([], ['access project context snapshot']);

    $tool = $this->tool()->execute();
    $this->assertTrue($tool->getResultStatus(), (string) $tool->getResultMessage());
    $snapshot = $tool->getResult()->getContextValues()['snapshot'];
    $this->assertSame(ContextSnapshotter::SCHEMA_VERSION, $snapshot['_meta']['schema_version']);
    $this->assertArrayHasKey('update_status', $snapshot['drupal']);
    $this->assertArrayHasKey('active_modules', $snapshot['drupal']);

    $tool = $this->tool();
    $tool->setInputValue('include_security_updates', FALSE);
    $tool->execute();
    $this->assertTrue($tool->getResultStatus(), (string) $tool->getResultMessage());
    $snapshot = $tool->getResult()->getContextValues()['snapshot'];
    $this->assertArrayNotHasKey('update_status', $snapshot['drupal']);
    $this->assertContains('update_status', $snapshot['_meta']['redactions']);
  }

  /**
   * Tests schema validity and service parity in each update data state.
   */
  public function testStatusCountParity(): void {
    $this->setUpCurrentUser([], ['access project context snapshot']);
    $check = function (string $state, array $nonZero): void {
      $tool = $this->tool()->execute();
      $this->assertTrue($tool->getResultStatus(), (string) $tool->getResultMessage());
      $snapshot = $tool->getResult()->getContextValues()['snapshot'];
      $this->assertSame($state, $snapshot['drupal']['update_status']['data_state']);
      $this->assertStatusCounts($snapshot, $nonZero);
      $service = $this->container->get('project_context_connector.context_snapshotter')->buildSnapshot();
      $this->assertSame(
        json_encode($service['drupal']['update_status'], JSON_THROW_ON_ERROR),
        json_encode($snapshot['drupal']['update_status'], JSON_THROW_ON_ERROR),
      );
    };

    $check('unavailable', []);
    $this->enableModules(['update']);
    $this->installConfig(['update']);
    $check('missing', []);
    $this->seedUpdateData();
    $check('current', ['security_update_available' => 1]);
  }

  /**
   * Tests that a failure holds no exception detail.
   */
  public function testNoExceptionDetail(): void {
    $this->setUpCurrentUser([], ['access project context snapshot']);
    $snapshotter = $this->createStub(ContextSnapshotter::class);
    $snapshotter->method('buildSnapshot')->willThrowException(new \RuntimeException('secret-internal-detail'));
    $this->container->set('project_context_connector.context_snapshotter', $snapshotter);

    $tool = $this->tool()->execute();
    $this->assertFalse($tool->getResultStatus());
    $this->assertStringNotContainsString('secret-internal-detail', (string) $tool->getResultMessage());
    $this->assertStringNotContainsString('RuntimeException', (string) $tool->getResultMessage());
  }

}
