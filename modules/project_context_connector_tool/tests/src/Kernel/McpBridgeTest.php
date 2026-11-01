<?php

declare(strict_types=1);

namespace Drupal\Tests\project_context_connector_tool\Kernel;

use Composer\InstalledVersions;
use Drupal\KernelTests\KernelTestBase;
use Drupal\mcp_server_tool_bridge\Entity\McpToolConfig;
use Drupal\project_context_connector\Service\ContextSnapshotter;
use Drupal\Tests\project_context_connector\Traits\SnapshotSchemaTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Mcp\Server\ClientGateway;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that MCP Server Tool Bridge can expose the adapter tool.
 */
#[Group('project_context_connector')]
#[RunTestsInSeparateProcesses]
final class McpBridgeTest extends KernelTestBase {

  use SnapshotSchemaTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'mcp_server',
    'mcp_server_tool_bridge',
    'tool',
    'user',
    'system',
    'serialization',
    'project_context_connector',
    'project_context_connector_tool',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    if (!InstalledVersions::isInstalled('drupal/mcp_server_tool_bridge')) {
      $this->markTestSkipped('drupal/mcp_server_tool_bridge is not installed.');
    }
    parent::setUp();
  }

  /**
   * Tests discovery, access and calls of the adapter through the bridge.
   */
  public function testBridgeDiscovery(): void {
    McpToolConfig::create([
      'id' => 'pcc_snapshot',
      'label' => 'Project snapshot',
      'tool_id' => 'project_context_snapshot',
      'status' => TRUE,
    ])->save();

    $manager = $this->container->get('plugin.manager.mcp_server.tool');
    $manager->clearCachedDefinitions();
    $this->assertArrayHasKey('tool_api__pcc_snapshot', $manager->getDefinitions());

    $this->installEntitySchema('user');
    $this->installConfig(['system', 'project_context_connector']);
    // User 1 bypasses permission checks, so use it up first.
    $this->createUser([]);
    $denied = $this->createUser([]);
    $allowed = $this->createUser(['access project context snapshot']);
    $tool = $manager->createInstance('tool_api__pcc_snapshot');
    $gateway = $this->createStub(ClientGateway::class);

    // The bridge uses the snapshot permission for listing and for calls.
    $this->assertFalse($tool->checkAccess($denied)->isAllowed());
    $this->assertTrue($tool->checkAccess($allowed)->isAllowed());

    $this->setCurrentUser($denied);
    $result = $tool->execute([], $gateway);
    $this->assertTrue($result->isError);
    $this->assertNull($result->structuredContent);

    $this->setCurrentUser($allowed);
    $result = $tool->execute([], $gateway);
    $this->assertFalse($result->isError);
    $this->assertSame(ContextSnapshotter::SCHEMA_VERSION, $result->structuredContent['snapshot']['_meta']['schema_version']);

    // Structured MCP results follow the published schema in each data state.
    $this->config('project_context_connector.settings')->set('expose_update_status', TRUE)->save();
    $call = function () use ($gateway): array {
      $tool = $this->container->get('plugin.manager.mcp_server.tool')->createInstance('tool_api__pcc_snapshot');
      $result = $tool->execute([], $gateway);
      $this->assertFalse($result->isError);
      return $result->structuredContent['snapshot'];
    };
    $snapshot = $call();
    $this->assertSame('unavailable', $snapshot['drupal']['update_status']['data_state']);
    $this->assertStatusCounts($snapshot);

    $this->enableModules(['update']);
    $this->installConfig(['update']);
    $this->setCurrentUser($allowed);
    $snapshot = $call();
    $this->assertSame('missing', $snapshot['drupal']['update_status']['data_state']);
    $this->assertStatusCounts($snapshot);

    $this->seedUpdateData();
    $snapshot = $call();
    $this->assertSame('current', $snapshot['drupal']['update_status']['data_state']);
    $this->assertStatusCounts($snapshot, ['security_update_available' => 1]);
  }

}
