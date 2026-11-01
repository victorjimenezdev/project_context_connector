<?php

declare(strict_types=1);

namespace Drupal\project_context_connector\Service;

use Composer\InstalledVersions;
use Drupal\Core\Logger\LoggerChannelInterface;

/**
 * Attributes an extension to its Composer package by install path.
 *
 * A submodule usually has a different name from its package, so the install
 * path is checked first. Metadata failures never fail a snapshot.
 */
class ComposerPackageResolver {

  /**
   * Real install paths keyed by package name, longest path first.
   *
   * @var array<string, string>|null
   */
  private ?array $installPaths = NULL;

  public function __construct(
    private readonly string $appRoot,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * Resolves an extension.
   *
   * @param string $relativePath
   *   The extension path relative to the Drupal root.
   *
   * @return array{name: string|null, version: string|null}
   *   The package name and version, each NULL when unknown.
   */
  public function resolve(string $relativePath): array {
    $real = realpath($this->appRoot . '/' . ltrim($relativePath, '/'));
    if ($real !== FALSE) {
      foreach ($this->installPaths() as $package => $installPath) {
        if ($real === $installPath || str_starts_with($real, $installPath . '/')) {
          return ['name' => $package, 'version' => $this->prettyVersion($package)];
        }
      }
    }
    return $this->readLocalComposerJson($real);
  }

  /**
   * Returns the real install paths of Drupal packages.
   *
   * @return array<string, string>
   *   The paths keyed by package name.
   */
  private function installPaths(): array {
    if ($this->installPaths !== NULL) {
      return $this->installPaths;
    }
    $this->installPaths = [];
    if (!class_exists(InstalledVersions::class)) {
      return $this->installPaths;
    }
    try {
      foreach (InstalledVersions::getInstalledPackages() as $package) {
        if (!str_starts_with($package, 'drupal/') || !InstalledVersions::isInstalled($package, FALSE)) {
          continue;
        }
        $path = InstalledVersions::getInstallPath($package);
        $real = is_string($path) ? realpath($path) : FALSE;
        if ($real !== FALSE) {
          $this->installPaths[$package] = $real;
        }
      }
    }
    catch (\Throwable $e) {
      $this->logger->notice('Composer install paths are unavailable: @type', ['@type' => get_class($e)]);
    }
    uasort($this->installPaths, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
    return $this->installPaths;
  }

  /**
   * Returns the installed version of a package.
   */
  private function prettyVersion(string $package): ?string {
    try {
      return InstalledVersions::getPrettyVersion($package);
    }
    catch (\Throwable) {
      return NULL;
    }
  }

  /**
   * Reads the composer.json in an extension directory, if it is usable.
   *
   * @return array{name: string|null, version: string|null}
   *   The package name and version, each NULL when unknown.
   */
  private function readLocalComposerJson(string|false $directory): array {
    $unknown = ['name' => NULL, 'version' => NULL];
    if ($directory === FALSE || !is_readable($directory . '/composer.json')) {
      return $unknown;
    }
    try {
      $json = json_decode((string) file_get_contents($directory . '/composer.json'), TRUE, 16, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      $this->logger->notice('Ignored a malformed composer.json for an extension.');
      return $unknown;
    }
    if (!is_array($json)) {
      return $unknown;
    }
    return [
      'name' => is_string($json['name'] ?? NULL) ? $json['name'] : NULL,
      'version' => is_string($json['version'] ?? NULL) ? $json['version'] : NULL,
    ];
  }

}
