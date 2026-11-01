<?php

declare(strict_types=1);

namespace Drupal\project_context_connector\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\State\StateInterface;
use Drupal\project_context_connector\Update\UpdateStatusCollector;

/**
 * Builds the sanitized project context snapshot.
 *
 * HTTP, Drush and the Tool adapter all use this one service, so a setting that
 * redacts a value does so for each of them. schema/snapshot.v2.schema.json
 * describes the result.
 */
class ContextSnapshotter {

  public const string SCHEMA_VERSION = '2.0';

  public function __construct(
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly ModuleExtensionList $moduleList,
    private readonly ThemeExtensionList $themeList,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly StateInterface $state,
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly ComposerPackageResolver $packageResolver,
    private readonly UpdateStatusCollector $updateCollector,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * Builds the snapshot.
   *
   * @return array<string, mixed>
   *   The snapshot data.
   */
  public function buildSnapshot(): array {
    $settings = $this->configFactory->get('project_context_connector.settings');
    $exposePaths = (bool) $settings->get('expose_paths');
    $exposeCustom = (bool) $settings->get('expose_custom_modules');
    $exposeUpdate = (bool) $settings->get('expose_update_status');
    $exposeDatabase = (bool) $settings->get('expose_database_version');

    $redactions = [];
    foreach ([
      'update_status' => $exposeUpdate,
      'database' => $exposeDatabase,
      'paths' => $exposePaths,
      'custom_extensions' => $exposeCustom,
    ] as $name => $exposed) {
      if (!$exposed) {
        $redactions[] = $name;
      }
    }

    // Disabled sources are not collected at all.
    $update = $exposeUpdate ? $this->updateCollector->collect() : NULL;
    $statuses = $update['statuses'] ?? [];

    $modules = [];
    foreach (array_keys($this->moduleHandler->getModuleList()) as $name) {
      $entry = $this->buildModule((string) $name, $statuses);
      if ($exposeCustom || in_array($entry['origin'], ['core', 'contrib'], TRUE)) {
        $entry['path'] = $exposePaths ? $entry['path'] : NULL;
        $modules[] = $entry;
      }
    }
    usort($modules, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

    $drupal = [
      'core_version' => \Drupal::VERSION,
      'php' => ['version' => PHP_VERSION],
      'database' => $exposeDatabase ? $this->databaseFacts() : ['driver' => NULL, 'version' => NULL],
      'themes' => $this->buildThemes($exposeCustom, $exposePaths),
      'config_flags' => $this->configFlags(),
      'active_modules' => $modules,
    ];
    if ($update !== NULL) {
      $drupal['update_status'] = $this->updateSummary($update);
    }

    return [
      'generated_at' => gmdate('c', $this->time->getCurrentTime()),
      'drupal' => $drupal,
      'rate_limit' => [
        'threshold' => (int) $settings->get('rate_limit_threshold'),
        'window_seconds' => (int) $settings->get('rate_limit_window'),
      ],
      '_meta' => [
        'schema_version' => self::SCHEMA_VERSION,
        'cache' => ['max_age' => (int) $settings->get('cache_max_age')],
        'redactions' => $redactions,
        'sources' => [
          'update_status' => $update === NULL ? 'redacted' : $update['data_state'],
          'versions' => 'composer_installed_then_extension_info',
        ],
      ],
    ];
  }

  /**
   * Removes the update data, for clients that do not request it.
   *
   * @param array<string, mixed> $snapshot
   *   A snapshot from buildSnapshot().
   *
   * @return array<string, mixed>
   *   The snapshot with update data redacted.
   */
  public static function withoutUpdateData(array $snapshot): array {
    unset($snapshot['drupal']['update_status']);
    foreach ($snapshot['drupal']['active_modules'] as &$module) {
      $module['security_status'] = 'unknown';
    }
    unset($module);
    if (!in_array('update_status', $snapshot['_meta']['redactions'], TRUE)) {
      $snapshot['_meta']['redactions'][] = 'update_status';
    }
    $snapshot['_meta']['sources']['update_status'] = 'redacted';
    return $snapshot;
  }

  /**
   * Builds one module entry.
   *
   * @param string $name
   *   The module machine name.
   * @param array<string, string> $statuses
   *   Update statuses keyed by project.
   *
   * @return array{name: string, label: string, version: string|null, composer: string|null, project: string|null, origin: string, path: string, security_status: string}
   *   The module entry.
   */
  private function buildModule(string $name, array $statuses): array {
    $info = $this->moduleList->getExtensionInfo($name);
    $path = $this->moduleList->getPath($name);
    $origin = self::classifyOrigin($path);
    $package = $this->packageFor($path, $origin);

    $version = $package['version'] ?? ($info['version'] ?? NULL);
    if ($origin === 'core') {
      $version = \Drupal::VERSION;
    }
    $project = is_string($info['project'] ?? NULL) ? $info['project'] : NULL;
    if ($project === NULL && $origin === 'core') {
      $project = 'drupal';
    }
    if ($project === NULL && is_string($package['name']) && str_starts_with($package['name'], 'drupal/')) {
      $project = substr($package['name'], strlen('drupal/'));
    }

    return [
      'name' => $name,
      'label' => is_string($info['name'] ?? NULL) && $info['name'] !== '' ? $info['name'] : $name,
      'version' => is_string($version) ? $version : NULL,
      'composer' => $package['name'],
      'project' => $project,
      'origin' => $origin,
      'path' => $path,
      'security_status' => $statuses[$project ?? ''] ?? 'unknown',
    ];
  }

  /**
   * Builds the theme section.
   *
   * @return array<string, mixed>
   *   The default and admin theme with details.
   */
  private function buildThemes(bool $exposeCustom, bool $exposePaths): array {
    $config = $this->configFactory->get('system.theme');
    $themes = ['default' => NULL, 'admin' => NULL];
    $details = [];
    foreach (array_keys($themes) as $role) {
      $name = $config->get($role);
      if (!is_string($name) || $name === '') {
        continue;
      }
      $detail = $this->buildThemeDetail($name);
      if (!$exposeCustom && !in_array($detail['origin'], ['core', 'contrib'], TRUE)) {
        continue;
      }
      $detail['path'] = $exposePaths ? $detail['path'] : NULL;
      $themes[$role] = $name;
      $details[$role] = $detail;
    }
    if ($details !== []) {
      $themes['details'] = $details;
    }
    return $themes;
  }

  /**
   * Builds one theme entry.
   *
   * @return array{name: string, label: string, version: string|null, composer: string|null, origin: string, path: string|null}
   *   The theme entry.
   */
  private function buildThemeDetail(string $name): array {
    try {
      $info = $this->themeList->getExtensionInfo($name);
      $path = $this->themeList->getPath($name);
    }
    catch (\Throwable) {
      return [
        'name' => $name,
        'label' => $name,
        'version' => NULL,
        'composer' => NULL,
        'origin' => 'unknown',
        'path' => NULL,
      ];
    }
    $origin = self::classifyOrigin($path);
    $package = $this->packageFor($path, $origin);
    $version = $origin === 'core' ? \Drupal::VERSION : ($package['version'] ?? ($info['version'] ?? NULL));
    return [
      'name' => $name,
      'label' => is_string($info['name'] ?? NULL) ? $info['name'] : $name,
      'version' => is_string($version) ? $version : NULL,
      'composer' => $package['name'],
      'origin' => $origin,
      'path' => $path,
    ];
  }

  /**
   * Returns package facts for an extension.
   *
   * @return array{name: string|null, version: string|null}
   *   The package name and version.
   */
  private function packageFor(string $path, string $origin): array {
    return $origin === 'core'
      ? ['name' => 'drupal/core', 'version' => \Drupal::VERSION]
      : $this->packageResolver->resolve($path);
  }

  /**
   * Reads database facts.
   *
   * @return array{driver: string|null, version: string|null}
   *   The driver and version.
   */
  private function databaseFacts(): array {
    try {
      return ['driver' => $this->database->driver(), 'version' => $this->database->version()];
    }
    catch (\Throwable $e) {
      $this->logger->warning('Database facts are unavailable: @type', ['@type' => get_class($e)]);
      return ['driver' => NULL, 'version' => NULL];
    }
  }

  /**
   * Reads the non-sensitive configuration flags.
   *
   * @return array<string, mixed>
   *   The flags.
   */
  private function configFlags(): array {
    $logging = $this->configFactory->get('system.logging');
    $performance = $this->configFactory->get('system.performance');
    return [
      'maintenance_mode' => (bool) $this->state->get('system.maintenance_mode', FALSE),
      'error_level' => $logging->get('error_level'),
      'css_preprocess' => $performance->get('css.preprocess'),
      'js_preprocess' => $performance->get('js.preprocess'),
      'page_cache_max_age' => $performance->get('cache.page.max_age'),
      'cron_last' => $this->state->get('system.cron_last'),
    ];
  }

  /**
   * Builds the update_status section.
   *
   * @param array{module_present: bool, data_state: string, last_checked: int|null, age_seconds: int|null, stale_after_seconds: int|null, statuses: array<string, string>} $update
   *   The collected data.
   *
   * @return array<string, mixed>
   *   The section.
   */
  private function updateSummary(array $update): array {
    // Every status is present, so JSON encoders always produce an object.
    $counts = array_merge(array_fill_keys(UpdateStatusCollector::STATUSES, 0), array_count_values($update['statuses']));
    return [
      'module_present' => $update['module_present'],
      'last_checked' => $update['last_checked'],
      'data_state' => $update['data_state'],
      'age_seconds' => $update['age_seconds'],
      'stale_after_seconds' => $update['stale_after_seconds'],
      'status_counts' => $counts,
      'note' => 'Statuses come from cached Update Manager data. This request made no fetch and queued no refresh.',
    ];
  }

  /**
   * Classifies an extension path.
   */
  private static function classifyOrigin(string $path): string {
    $path = ltrim($path, '/');
    if ($path === '') {
      return 'unknown';
    }
    if (str_starts_with($path, 'core/')) {
      return 'core';
    }
    // The outermost ownership namespace owns everything nested inside it. A
    // site or other directory name is not a namespace. The final segment is
    // the extension's own directory, not an ownership marker.
    $segments = array_slice(explode('/', $path), 0, -1);
    foreach ($segments as $index => $segment) {
      $owner = $segments[$index + 1] ?? NULL;
      if (in_array($segment, ['modules', 'themes', 'profiles'], TRUE) && ($owner === 'contrib' || $owner === 'custom')) {
        return $owner;
      }
    }
    return 'unknown';
  }

}
