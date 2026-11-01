<?php

declare(strict_types=1);

namespace Drupal\Tests\project_context_connector\Kernel;

use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\KernelTests\KernelTestBase;
use Drupal\project_context_connector\Service\ComposerPackageResolver;
use Drupal\project_context_connector\Service\ContextSnapshotter;
use Drupal\project_context_connector\Update\UpdateStatusCollector;
use Drupal\update\UpdateManagerInterface;
use Drupal\Tests\project_context_connector\Traits\SnapshotSchemaTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests snapshot exposure, update data and the published schema.
 */
#[Group('project_context_connector')]
#[RunTestsInSeparateProcesses]
final class ContextSnapshotterKernelTest extends KernelTestBase {

  use SnapshotSchemaTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'project_context_connector'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'project_context_connector']);
  }

  /**
   * Returns a fresh snapshot.
   *
   * @return array<string, mixed>
   *   The snapshot.
   */
  private function snapshot(): array {
    return $this->container->get('project_context_connector.context_snapshotter')->buildSnapshot();
  }

  /**
   * Sets settings.
   *
   * @param array<string, mixed> $values
   *   The values.
   */
  private function configure(array $values): void {
    $config = $this->config('project_context_connector.settings');
    foreach ($values as $key => $value) {
      $config->set($key, $value);
    }
    $config->save();
  }

  /**
   * Tests the safe profile, redaction metadata and zero max age.
   */
  public function testFreshInstallProfile(): void {
    $data = $this->snapshot();
    $this->assertMatchesSchema($data);
    $this->assertSame(0, $data['_meta']['cache']['max_age']);
    $this->assertSame('2.0', $data['_meta']['schema_version']);
    $this->assertSame(['update_status', 'database', 'paths', 'custom_extensions'], $data['_meta']['redactions']);
    $this->assertSame('redacted', $data['_meta']['sources']['update_status']);
    $this->assertSame(['driver' => NULL, 'version' => NULL], $data['drupal']['database']);
    $this->assertArrayNotHasKey('update_status', $data['drupal']);
    $names = array_column($data['drupal']['active_modules'], 'name');
    $this->assertContains('system', $names);
    foreach ($data['drupal']['active_modules'] as $module) {
      $this->assertNull($module['path']);
    }
    $this->assertMatchesSchema(json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/examples/snapshot.example.json')));
  }

  /**
   * Tests that a custom module disappears with the custom setting.
   */
  public function testCustomExtensionPolicy(): void {
    $real = $this->container->get('extension.list.module');
    $list = $this->createStub(ModuleExtensionList::class);
    $list->method('getExtensionInfo')->willReturnCallback(static fn(string $name): array => ['name' => $name]);
    // The outermost ownership directory decides, whichever name it has.
    $paths = [
      'user' => 'modules/custom/user',
      'system' => 'modules/custom/pack/contrib/system',
      'project_context_connector' => 'modules/contrib/pack/custom/project_context_connector',
    ];
    $list->method('getPath')->willReturnCallback(static function (string $name) use (&$paths, $real): string {
      return $paths[$name] ?? $real->getPath($name);
    });
    $themePaths = [
      'nested_custom' => 'themes/custom/pack/contrib/nested_custom',
      'nested_contrib' => 'themes/contrib/pack/custom/nested_contrib',
    ];
    $themes = $this->createStub(ThemeExtensionList::class);
    $themes->method('getExtensionInfo')->willReturnCallback(static fn(string $name): array => ['name' => $name]);
    $themes->method('getPath')->willReturnCallback(static function (string $name) use (&$themePaths): string {
      return $themePaths[$name];
    });
    $this->config('system.theme')->set('default', 'nested_custom')->set('admin', 'nested_contrib')->save();
    $build = fn(): array => (new ContextSnapshotter(
      $this->container->get('module_handler'),
      $list,
      $themes,
      $this->container->get('config.factory'),
      $this->container->get('state'),
      $this->container->get('database'),
      $this->container->get('datetime.time'),
      $this->container->get('project_context_connector.composer_package_resolver'),
      $this->container->get('project_context_connector.update_status_collector'),
      $this->container->get('logger.channel.project_context_connector'),
    ))->buildSnapshot();

    $safe = $build()['drupal'];
    $redacted = array_column($safe['active_modules'], 'name');
    $this->assertNotContains('user', $redacted);
    $this->assertNotContains('system', $redacted);
    $this->assertContains('project_context_connector', $redacted);
    $this->assertNull($safe['themes']['default']);
    $this->assertSame('nested_contrib', $safe['themes']['admin']);

    $this->configure(['expose_custom_modules' => TRUE, 'expose_paths' => TRUE]);
    $byName = array_column($build()['drupal']['active_modules'], NULL, 'name');
    $this->assertSame('custom', $byName['user']['origin']);
    $this->assertSame('modules/custom/user', $byName['user']['path']);
    $this->assertSame('custom', $byName['system']['origin']);
    $this->assertSame('contrib', $byName['project_context_connector']['origin']);
    $exposed = $build()['drupal']['themes'];
    $this->assertSame('custom', $exposed['details']['default']['origin']);
    $this->assertSame('contrib', $exposed['details']['admin']['origin']);

    // A site name or another directory name is not an ownership namespace.
    $paths = [
      'user' => 'sites/contrib/modules/custom/user',
      'system' => 'sites/custom/modules/contrib/system',
      'project_context_connector' => 'sites/contrib/modules/pack/contrib/project_context_connector',
    ];
    $themePaths = [
      'nested_custom' => 'sites/contrib/themes/custom/nested_custom',
      'nested_contrib' => 'sites/custom/themes/contrib/nested_contrib',
    ];
    $sites = $build()['drupal'];
    $byName = array_column($sites['active_modules'], NULL, 'name');
    $this->assertSame('custom', $byName['user']['origin']);
    $this->assertSame('contrib', $byName['system']['origin']);
    $this->assertSame('unknown', $byName['project_context_connector']['origin']);
    $this->assertSame('custom', $sites['themes']['details']['default']['origin']);
    $this->assertSame('contrib', $sites['themes']['details']['admin']['origin']);

    $this->configure(['expose_custom_modules' => FALSE, 'expose_paths' => FALSE]);
    $safe = $build()['drupal'];
    $redacted = array_column($safe['active_modules'], 'name');
    $this->assertNotContains('user', $redacted);
    $this->assertContains('system', $redacted);
    $this->assertNotContains('project_context_connector', $redacted);
    $this->assertNull($safe['themes']['default']);
    $this->assertSame('nested_contrib', $safe['themes']['admin']);

    // A site named after an ownership directory does not change the result.
    $themePaths['nested_custom'] = 'sites/custom/themes/custom/nested_custom';
    $themePaths['nested_contrib'] = 'sites/contrib/themes/contrib/nested_contrib';
    $safe = $build()['drupal'];
    $this->assertNull($safe['themes']['default']);
    $this->assertSame('nested_contrib', $safe['themes']['admin']);
  }

  /**
   * Tests that a failed calculation does not report current data.
   */
  public function testFailedUpdateCalculation(): void {
    $this->enableModules(['update']);
    $this->installConfig(['update']);
    $now = (int) $this->container->get('datetime.time')->getRequestTime();
    $this->container->get('state')->set('update.last_check', $now - 60);
    $this->container->get('keyvalue.expirable')->get('update_available_releases')
      ->set('drupal', ['title' => 'Drupal core', 'last_fetch' => $now, 'releases' => []]);
    $failingManager = new class() {

      /**
       * Fails as a broken calculation would.
       */
      public function calculateProjectData(array $available): array {
        throw new \RuntimeException('Calculation failed.');
      }

    };

    $result = (new UpdateStatusCollector(
      $this->container->get('module_handler'),
      $this->container->get('keyvalue.expirable'),
      $this->container->get('state'),
      $this->container->get('config.factory'),
      $this->container->get('datetime.time'),
      $this->container->get('logger.channel.project_context_connector'),
      $failingManager,
    ))->collect();

    $this->assertSame('unavailable', $result['data_state']);
    $this->assertSame([], $result['statuses']);
    $this->assertTrue($result['module_present']);
    $this->assertSame($now - 60, $result['last_checked']);
    $this->assertSame(60, $result['age_seconds']);
    $this->assertIsInt($result['stale_after_seconds']);
    $this->assertStringNotContainsString('Calculation failed', (string) json_encode($result));
  }

  /**
   * Tests attribution by install path and malformed Composer metadata.
   */
  public function testComposerAttribution(): void {
    $logger = $this->container->get('logger.channel.project_context_connector');
    $root = $this->root;

    // A submodule belongs to the package that contains it.
    $path = $this->container->get('extension.list.module')->getPath('project_context_connector') . '/modules/project_context_connector_tool';
    $package = (new ComposerPackageResolver($root, $logger))->resolve($path);
    $this->assertSame('drupal/project_context_connector', $package['name']);

    // A malformed or scalar composer.json is ignored.
    $directory = sys_get_temp_dir() . '/pcc-' . bin2hex(random_bytes(4));
    mkdir($directory . '/bad', 0700, TRUE);
    mkdir($directory . '/scalar', 0700, TRUE);
    mkdir($directory . '/good', 0700, TRUE);
    file_put_contents($directory . '/bad/composer.json', '{ this is not json');
    file_put_contents($directory . '/scalar/composer.json', '42');
    file_put_contents($directory . '/good/composer.json', '{"name":"vendor/good","version":"1.2.3"}');
    $resolver = new ComposerPackageResolver($directory, $logger);
    $this->assertSame(['name' => NULL, 'version' => NULL], $resolver->resolve('bad'));
    $this->assertSame(['name' => NULL, 'version' => NULL], $resolver->resolve('scalar'));
    $this->assertSame(['name' => NULL, 'version' => NULL], $resolver->resolve('absent'));
    $this->assertSame(['name' => 'vendor/good', 'version' => '1.2.3'], $resolver->resolve('good'));
  }

  /**
   * Tests that disabling update exposure skips collection.
   */
  public function testUpdateExposureOffSkipsCollection(): void {
    $collector = $this->createMock('Drupal\project_context_connector\Update\UpdateStatusCollector');
    $collector->expects($this->never())->method('collect');
    $this->container->set('project_context_connector.update_status_collector', $collector);
    $this->assertArrayNotHasKey('update_status', $this->snapshot()['drupal']);
  }

  /**
   * Tests zero counts when Update Manager is absent.
   */
  public function testUnavailableUpdateData(): void {
    $this->configure(['expose_update_status' => TRUE]);
    $data = $this->snapshot();
    $this->assertSame('unavailable', $data['drupal']['update_status']['data_state']);
    $this->assertStatusCounts($data);
  }

  /**
   * Tests missing, current and stale data with no fetch and no queue.
   */
  public function testCachedUpdateData(): void {
    $this->enableModules(['update']);
    $this->installConfig(['update']);
    $this->configure(['expose_update_status' => TRUE]);
    $queue = $this->container->get('queue')->get('update_fetch_tasks');
    $client = $this->createMock('GuzzleHttp\ClientInterface');
    $client->expects($this->never())->method('request');
    $this->container->set('http_client', $client);

    // Missing.
    $data = $this->snapshot();
    $this->assertMatchesSchema($data);
    $this->assertSame('missing', $data['drupal']['update_status']['data_state']);
    $this->assertStatusCounts($data);
    $this->assertNull($data['drupal']['update_status']['last_checked']);
    $this->assertSame(0, (int) $queue->numberOfItems());

    // Current, with statuses from the stored calculation.
    $now = (int) $this->container->get('datetime.time')->getRequestTime();
    $this->container->get('state')->set('update.last_check', $now - 60);
    $this->container->get('keyvalue.expirable')->get('update_available_releases')
      ->set('drupal', ['title' => 'Drupal core', 'last_fetch' => $now, 'releases' => []]);
    $this->container->get('keyvalue.expirable')->get('update')
      ->set('update_project_data', ['drupal' => ['status' => UpdateManagerInterface::NOT_SECURE]]);
    $data = $this->snapshot();
    $this->assertMatchesSchema($data);
    $this->assertSame('current', $data['drupal']['update_status']['data_state']);
    $this->assertSame(60, $data['drupal']['update_status']['age_seconds']);
    $this->assertStatusCounts($data, ['security_update_available' => 1]);
    $system = array_column($data['drupal']['active_modules'], NULL, 'name')['system'];
    $this->assertSame('security_update_available', $system['security_status']);

    // Stale, and other statuses.
    $this->container->get('state')->set('update.last_check', $now - 86400 * 30);
    $this->container->get('keyvalue.expirable')->get('update')
      ->set('update_project_data', ['drupal' => ['status' => UpdateManagerInterface::NOT_SUPPORTED]]);
    $data = $this->snapshot();
    $this->assertSame('stale', $data['drupal']['update_status']['data_state']);
    $system = array_column($data['drupal']['active_modules'], NULL, 'name')['system'];
    $this->assertSame('not_supported', $system['security_status']);
    $this->assertSame(0, (int) $queue->numberOfItems());
  }

  /**
   * Tests the injected clock.
   */
  public function testInjectedTime(): void {
    $time = $this->createStub('Drupal\Component\Datetime\TimeInterface');
    $time->method('getCurrentTime')->willReturn(1700000000);
    $time->method('getRequestTime')->willReturn(1700000000);
    $this->container->set('datetime.time', $time);
    $this->assertSame('2023-11-14T22:13:20+00:00', $this->snapshot()['generated_at']);
  }

}
