<?php

declare(strict_types=1);

namespace Drupal\Tests\project_context_connector\Kernel;

use Drupal\Core\Config\ConfigImporterException;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests typed constraints, configuration import and the 2.x update.
 */
#[Group('project_context_connector')]
#[RunTestsInSeparateProcesses]
final class SettingsValidationKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'project_context_connector'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['project_context_connector']);
  }

  /**
   * Tests the constraints on valid and invalid data.
   */
  public function testTypedConstraints(): void {
    $base = $this->config('project_context_connector.settings')->getRawData();
    $typed = $this->container->get('config.typed');
    $validate = static fn(array $data): int => count($typed
      ->createFromNameAndData('project_context_connector.settings', $data + $base)
      ->validate());

    $this->assertSame(0, $validate([]));
    $this->assertSame(0, $validate(['cache_max_age' => 0]));
    $origins = ['https://a.example.com', 'https://*.example.com:8443', '*.example.org'];
    $this->assertSame(0, $validate(['allowed_origins' => $origins]));
    foreach ([
      ['allowed_origins' => ['https://a.example.com/path']],
      ['allowed_origins' => ['https://user@a.example.com']],
      ['allowed_origins' => ['*']],
      ['rate_limit_threshold' => 0],
      ['rate_limit_window' => 90000],
      ['failed_auth_threshold' => 0],
      ['cache_max_age' => -1],
    ] as $invalid) {
      $this->assertGreaterThan(0, $validate($invalid), json_encode($invalid, JSON_THROW_ON_ERROR));
    }
  }

  /**
   * Tests that a configuration import rejects invalid settings.
   */
  public function testImportValidation(): void {
    $this->copyConfig($this->container->get('config.storage'), $this->container->get('config.storage.sync'));
    $sync = $this->container->get('config.storage.sync');
    $data = $sync->read('project_context_connector.settings');
    $data['allowed_origins'] = ['https://user@evil.example.com/x?y#z'];
    $sync->write('project_context_connector.settings', $data);

    $importer = $this->configImporter();
    $this->assertTrue($importer->getStorageComparer()->hasChanges());
    try {
      $importer->import();
      $this->fail('The import accepted an invalid origin.');
    }
    catch (ConfigImporterException) {
      $this->assertStringContainsString('allowed_origins', implode("\n", $importer->getErrors()));
    }
  }

  /**
   * Tests that the update keeps 1.x behavior and values.
   */
  public function testUpdateKeepsInstalledConfiguration(): void {
    $this->config('project_context_connector.settings')->setData([
      'allowed_origins' => ['https://example.com'],
      'enable_cors' => TRUE,
      'rate_limit_threshold' => 7,
      'rate_limit_window' => 9,
      'cache_max_age' => 300,
      'expose_update_status' => TRUE,
      'expose_database_version' => TRUE,
    ])->save();

    $this->container->get('module_handler')->loadInclude('project_context_connector', 'install');
    project_context_connector_update_20001();

    $config = $this->config('project_context_connector.settings');
    $this->assertSame(['https://example.com'], $config->get('allowed_origins'));
    $this->assertTrue($config->get('enable_cors'));
    $this->assertSame(7, $config->get('rate_limit_threshold'));
    $this->assertSame(300, $config->get('cache_max_age'));
    $this->assertTrue($config->get('expose_paths'));
    $this->assertTrue($config->get('expose_custom_modules'));
    $this->assertSame(20, $config->get('failed_auth_threshold'));
    $this->assertSame(300, $config->get('failed_auth_window'));
  }

}
