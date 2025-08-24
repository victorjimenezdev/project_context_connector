<?php

declare(strict_types=1);

namespace Drupal\project_context_connector\Service;

use Composer\InstalledVersions;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\State\StateInterface;
use Symfony\Component\HttpFoundation\RequestStack;

// These classes are in core's Update module namespace. They exist even if the
// module is disabled; we guard all usages behind moduleExists('update').
use Drupal\update\UpdateFetcherInterface;
use Drupal\update\UpdateManagerInterface;

/**
 * Builds the sanitized project context snapshot.
 *
 * Returns only non-PII metadata useful for prompt building and automation.
 *
 * @psalm-type ModuleEntry=array{
 *   name: string,
 *   label: string,
 *   version: string|null,
 *   composer: string|null,
 *   project: string|null,
 *   origin: string,
 *   path: string|null,
 *   security_status: string
 * }
 * @psalm-type ThemeDetail=array{
 *   name: string,
 *   label: string,
 *   version: string|null,
 *   composer: string|null,
 *   origin: string,
 *   path: string|null
 * }
 */
final class ContextSnapshotter {

  public function __construct(
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly ModuleExtensionList $moduleList,
    private readonly ThemeExtensionList $themeList,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly StateInterface $state,
    private readonly Connection $database,
    private readonly RequestStack $requestStack,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * Build the snapshot as an array (encoded by the controller/Drush).
   *
   * @return array{
   *   generated_at: string,
   *   drupal: array{
   *     core_version: string,
   *     php: array{version: string},
   *     database: array{driver: string|null, version: string|null},
   *     themes: array{
   *       default: string|null,
   *       admin: string|null,
   *       details?: array{default?: ThemeDetail|null, admin?: ThemeDetail|null}
   *     },
   *     config_flags: array{
   *       maintenance_mode: bool,
   *       error_level: string|null,
   *       css_preprocess: bool|null,
   *       js_preprocess: bool|null,
   *       page_cache_max_age: int|null,
   *       cron_last: int|null
   *     },
   *     update_status?: array{
   *       module_present: bool,
   *       last_checked: int|null,
   *       note: string
   *     },
   *     active_modules: array<int, ModuleEntry>
   *   },
   *   rate_limit: array{threshold: int, window_seconds: int},
   *   _meta: array{cache: array{max_age: int}}
   *   }
   */
  public function buildSnapshot(): array {
    $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c');

    // Theme facts.
    $system_theme = $this->configFactory->get('system.theme');
    $default_theme = $system_theme->get('default');
    $admin_theme = $system_theme->get('admin');

    // Logging/performance flags (non-PII).
    $logging = $this->configFactory->get('system.logging');
    $performance = $this->configFactory->get('system.performance');

    // Database facts (non-PII).
    $dbDriver = NULL;
    $dbVersion = NULL;
    try {
      $dbDriver = $this->database->driver();
      $dbVersion = $this->database->version();
    }
    catch (\Throwable $e) {
      $this->logger->warning('Database driver/version unavailable: @m', ['@m' => $e->getMessage()]);
    }

    // Precompute per-project security status map from Update Manager (cached).
    $securityByProject = $this->collectSecurityStatuses();

    // Build active module list using ModuleExtensionList (no direct calls on
    // SplFileInfo-based Extension objects).
    $activeModules = [];
    foreach ($this->moduleHandler->getModuleList() as $name => $extension) {
      $info = $this->moduleList->getExtensionInfo($name) ?? [];
      $label = (string) $this->moduleList->getName($name);
      if ($label === '' && isset($info['name'])) {
        $label = (string) $info['name'];
      }

      $path = $this->moduleList->getPath($name);
      $origin = $this->classifyOrigin($path);

      $version = $info['version'] ?? NULL;
      $composer = NULL;
      $project = isset($info['project']) && is_string($info['project']) ? $info['project'] : NULL;

      // Prefer Composer runtime data when available.
      try {
        if (class_exists(InstalledVersions::class)) {
          $candidate = 'drupal/' . $name;
          if (InstalledVersions::isInstalled($candidate)) {
            $composer = $candidate;
            $version = InstalledVersions::getPrettyVersion($candidate) ?? $version;
          }
          elseif ($project) {
            $alt = 'drupal/' . $project;
            if (InstalledVersions::isInstalled($alt)) {
              $composer = $alt;
              $version = InstalledVersions::getPrettyVersion($alt) ?? $version;
            }
          }
        }
      }
      catch (\Throwable $e) {
        // Never fail the snapshot for version lookups.
        $this->logger->notice('Composer version lookup failed for @module: @m',
          [
            '@module' => $name,
            '@m' => $e->getMessage(),
          ]
        );
      }

      // Core module normalization.
      if ($origin === 'core') {
        $composer = $composer ?? 'drupal/core';
        $version = $version ?? \Drupal::VERSION;
        $project = $project ?? 'drupal';
      }

      // Fallback: local composer.json inside the module directory.
      if ($composer === NULL || $version === NULL) {
        $local = $this->readLocalComposerJson($path);
        if ($composer === NULL && $local['name'] !== NULL) {
          $composer = $local['name'];
        }
        if ($version === NULL && $local['version'] !== NULL) {
          $version = $local['version'];
        }
      }

      // If still no explicit project, derive from a drupal/* Composer package.
      if ($project === NULL && is_string($composer) && str_starts_with($composer, 'drupal/')) {
        $project = substr($composer, strlen('drupal/'));
      }

      // Compute security status based on per-project map (if available).
      $security_status = 'unknown';
      if (is_string($project) && $project !== '' && isset($securityByProject[$project])) {
        $security_status = $securityByProject[$project];
      }

      $activeModules[] = [
        'name' => $name,
        'label' => $label !== '' ? $label : $name,
        'version' => is_string($version) ? $version : NULL,
        'composer' => $composer,
        'project' => $project,
        'origin' => $origin,
        'path' => $path,
        'security_status' => $security_status,
      ];
    }

    // Stable ordering for readability/diffs.
    usort($activeModules, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

    // Configurable cache and rate-limit.
    $settings = $this->configFactory->get('project_context_connector.settings');
    $maxAge = (int) $settings->get('cache_max_age') ?: 300;
    $limit = (int) $settings->get('rate_limit_threshold') ?: 60;
    $window = (int) $settings->get('rate_limit_window') ?: 60;
    $exposeUpdate = (bool) $settings->get('expose_update_status');

    $themes = [
      'default' => $default_theme,
      'admin' => $admin_theme,
    ];
    $details = [];
    if (is_string($default_theme)) {
      $details['default'] = $this->buildThemeDetail($default_theme);
    }
    if (is_string($admin_theme)) {
      $details['admin'] = $this->buildThemeDetail($admin_theme);
    }
    if (!empty($details)) {
      $themes['details'] = $details;
    }

    $drupal = [
      'core_version' => \Drupal::VERSION,
      'php' => ['version' => PHP_VERSION],
      'database' => ['driver' => $dbDriver, 'version' => $dbVersion],
      'themes' => $themes,
      'config_flags' => [
        'maintenance_mode' => (bool) $this->state->get('system.maintenance_mode', FALSE),
        'error_level' => $logging->get('error_level'),
        'css_preprocess' => $performance->get('css.preprocess'),
        'js_preprocess' => $performance->get('js.preprocess'),
        'page_cache_max_age' => $performance->get('cache.page.max_age'),
        'cron_last' => $this->state->get('system.cron_last'),
      ],
      'active_modules' => $activeModules,
    ];

    if ($exposeUpdate) {
      $updatePresent = $this->moduleHandler->moduleExists('update');
      $lastChecked = NULL;
      if ($updatePresent) {
        foreach (['update_last_check', 'update.last_check'] as $candidate) {
          $value = $this->state->get($candidate);
          if (is_int($value)) {
            $lastChecked = $value;
            break;
          }
        }
      }
      $drupal['update_status'] = [
        'module_present' => $updatePresent,
        'last_checked' => $lastChecked,
        'note' => 'Per-project security_status is computed from cached Update Manager data only; it remains "unknown" until update data exists.',
      ];
    }

    return [
      'generated_at' => $now,
      'drupal' => $drupal,
      'rate_limit' => [
        'threshold' => $limit,
        'window_seconds' => $window,
      ],
      '_meta' => [
        'cache' => ['max_age' => $maxAge],
      ],
    ];
  }

  /**
   * Read a local composer.json in the extension directory, if any.
   *
   * @return array{name: string|null, version: string|null}
   *   array containing name and version.
   */
  private function readLocalComposerJson(?string $relativePath): array {
    if (!is_string($relativePath) || $relativePath === '') {
      return ['name' => NULL, 'version' => NULL];
    }
    $full = (defined('DRUPAL_ROOT') ? DRUPAL_ROOT : getcwd()) . '/' . ltrim($relativePath, '/') . '/composer.json';
    if (!is_readable($full)) {
      return ['name' => NULL, 'version' => NULL];
    }
    try {
      $raw = (string) file_get_contents($full);
      $json = json_decode($raw, TRUE, flags: JSON_THROW_ON_ERROR);
      $name = isset($json['name']) && is_string($json['name']) ? $json['name'] : NULL;
      $version = isset($json['version']) && is_string($json['version']) ? $json['version'] : NULL;
      return ['name' => $name, 'version' => $version];
    }
    catch (\Throwable $e) {
      // Do not fail snapshot on malformed composer.json.
      $this->logger->notice('Local composer.json read failed at @p: @m', ['@p' => $full, '@m' => $e->getMessage()]);
      return ['name' => NULL, 'version' => NULL];
    }
  }

  /**
   * Classify origin from a relative extension path.
   */
  private function classifyOrigin(?string $path): string {
    if (!is_string($path) || $path === '') {
      return 'unknown';
    }
    $p = ltrim($path, '/');

    if (str_starts_with($p, 'core/modules/') || str_starts_with($p, 'core/themes/') || str_starts_with($p, 'core/profiles/')) {
      return 'core';
    }
    if (str_contains($p, '/contrib/') || str_starts_with($p, 'modules/contrib/') || str_starts_with($p, 'themes/contrib/') || str_starts_with($p, 'profiles/contrib/')) {
      return 'contrib';
    }
    if (str_contains($p, '/custom/') || str_starts_with($p, 'modules/custom/') || str_starts_with($p, 'themes/custom/') || str_starts_with($p, 'profiles/custom/')) {
      return 'custom';
    }
    return 'unknown';
  }

  /**
   * Build theme detail metadata similar to module entries.
   *
   * @return ThemeDetail|null
   *   return theme detail.
   */
  private function buildThemeDetail(string $themeName): ?array {
    try {
      $info = $this->themeList->getExtensionInfo($themeName) ?? [];
      $label = is_string($info['name'] ?? NULL) ? (string) $info['name'] : $themeName;
      $path = $this->themeList->getPath($themeName);
      $origin = $this->classifyOrigin($path);

      $version = $info['version'] ?? NULL;
      $composer = NULL;

      // Composer packages commonly drupal/<theme>.
      try {
        if (class_exists(InstalledVersions::class)) {
          $candidate = 'drupal/' . $themeName;
          if (InstalledVersions::isInstalled($candidate)) {
            $composer = $candidate;
            $version = InstalledVersions::getPrettyVersion($candidate) ?? $version;
          }
          elseif (!empty($info['project'])) {
            $alt = 'drupal/' . $info['project'];
            if (InstalledVersions::isInstalled($alt)) {
              $composer = $alt;
              $version = InstalledVersions::getPrettyVersion($alt) ?? $version;
            }
          }
        }
      }
      catch (\Throwable $e) {
        $this->logger->notice('Composer version lookup failed for theme @t: @m',
        [
          '@t' => $themeName,
          '@m' => $e->getMessage(),
        ]
        );
      }

      // Fallback to local composer.json.
      if ($composer === NULL || $version === NULL) {
        $local = $this->readLocalComposerJson($path);
        if ($composer === NULL && $local['name'] !== NULL) {
          $composer = $local['name'];
        }
        if ($version === NULL && $local['version'] !== NULL) {
          $version = $local['version'];
        }
      }

      // Core theme normalization.
      if ($origin === 'core') {
        $composer = $composer ?? 'drupal/core';
        $version = $version ?? \Drupal::VERSION;
      }

      return [
        'name' => $themeName,
        'label' => $label,
        'version' => is_string($version) ? $version : NULL,
        'composer' => $composer,
        'origin' => $origin,
        'path' => $path,
      ];
    }
    catch (\Throwable $e) {
      $this->logger->notice('Theme detail build failed for @t: @m', ['@t' => $themeName, '@m' => $e->getMessage()]);
      return [
        'name' => $themeName,
        'label' => $themeName,
        'version' => NULL,
        'composer' => NULL,
        'origin' => 'unknown',
        'path' => NULL,
      ];
    }
  }

  /**
   * Safely compute per-project security status from Update Manager caches.
   *
   * No outbound requests are performed. If the Update Manager has never fetched
   * data, this returns an empty map and module entries will show "unknown".
   *
   * @return array<string,string>
   *   Map of project shortname => normalized status.
   */
  private function collectSecurityStatuses(): array {
    if (!$this->moduleHandler->moduleExists('update')) {
      return [];
    }

    try {
      // Ensure helper functions are loaded.
      $this->moduleHandler->loadInclude('update', 'module');
      $this->moduleHandler->loadInclude('update', 'inc', 'update.manager');

      $available = [];
      if (function_exists('update_get_available')) {
        // FALSE reads from cache; TRUE would trigger a refresh.
        $available = update_get_available(FALSE);
      }

      $calculated = [];
      if (function_exists('update_calculate_project_data')) {
        // Pass the AVAILABLE data into the calculator.
        $calculated = update_calculate_project_data(is_array($available) ? $available : []);
      }

      $map = [];
      foreach ($calculated as $project => $data) {
        $status = $data['status'] ?? NULL;
        if (is_int($status)) {
          $map[$project] = $this->normalizeUpdateStatus($status);
        }
      }

      return $map;
    }
    catch (\Throwable $e) {
      // Never fail the snapshot due to update module internals.
      $this->logger->notice('Security status map unavailable: @m', ['@m' => $e->getMessage()]);
      return [];
    }
  }

  /**
   * Normalize Update Manager status codes to stable strings.
   */
  private function normalizeUpdateStatus(int $status): string {
    // Map known UpdateManagerInterface constants to readable codes.
    return match ($status) {
      UpdateManagerInterface::CURRENT => 'current',
      UpdateManagerInterface::NOT_CURRENT => 'update_available',
      UpdateManagerInterface::NOT_SECURE => 'security_update_available',
      UpdateManagerInterface::NOT_SUPPORTED => 'not_supported',
      UpdateManagerInterface::REVOKED => 'revoked',
      // UpdateFetcher "status" fallbacks occasionally appear in processed data.
      UpdateFetcherInterface::NOT_FETCHED => 'not_fetched',
      default => 'unknown',
    };
  }

}
