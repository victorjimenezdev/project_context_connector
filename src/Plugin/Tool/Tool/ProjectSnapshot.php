<?php

declare(strict_types=1);

namespace Drupal\project_context_connector\Plugin\tool\Tool;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\project_context_connector\Service\ContextSnapshotter;
use Drupal\tool\Attribute\Tool;
use Drupal\tool\ExecutableResult;
use Drupal\tool\Tool\ToolBase;
use Drupal\tool\Tool\ToolOperation;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides project snapshot data via MCP.
 *
 * Exposes the same read-only project context available via HTTP endpoints
 * and Drush commands, making it accessible to AI assistants through MCP.
 */
#[Tool(
  id: 'project_context_snapshot',
  label: new TranslatableMarkup('Get Project Snapshot'),
  description: new TranslatableMarkup('Returns sanitized, read-only project '
    . 'context including modules, themes, Drupal version, and configuration.'),
  operation: ToolOperation::Read,
)]
final class ProjectSnapshot extends ToolBase {

  /**
   * The context snapshotter service.
   *
   * @var \Drupal\project_context_connector\Service\ContextSnapshotter
   */
  protected ContextSnapshotter $snapshotter;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->snapshotter = $container->get('project_context_connector.context_snapshotter');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(array $values, AccountInterface $account, bool $return_as_object = FALSE): bool|AccessResultInterface {
    $access = AccessResult::allowed();
    return $return_as_object ? $access : $access->isAllowed();
  }

  /**
   * {@inheritdoc}
   */
  protected function doExecute(array $values): ExecutableResult {
    try {
      // Get the snapshot data from the service.
      $snapshot = $this->snapshotter->buildSnapshot();

      // Format the response as markdown for better readability.
      $formatted = $this->formatSnapshot($snapshot);

      return ExecutableResult::success(
        new TranslatableMarkup('Project snapshot generated successfully.'),
        ['snapshot' => $formatted, 'raw_data' => $snapshot]
      );
    }
    catch (\Exception $e) {
      return ExecutableResult::failure(
        new TranslatableMarkup('Failed to generate project snapshot: @error', [
          '@error' => $e->getMessage(),
        ])
      );
    }
  }

  /**
   * Formats snapshot data as human-readable markdown text.
   *
   * @param array $snapshot
   *   The snapshot data array.
   *
   * @return string
   *   Formatted snapshot as markdown text.
   */
  private function formatSnapshot(array $snapshot): string {
    $output = [];

    // Project metadata.
    $output[] = "# Project Context Snapshot\n";
    $output[] = "**Generated:** " . ($snapshot['generated_at'] ?? 'unknown');
    $output[] = "";

    // Platform information.
    if (!empty($snapshot['drupal'])) {
      $drupal = $snapshot['drupal'];
      $output[] = "## Platform";
      $output[] = "- **Drupal:** " . ($drupal['core_version'] ?? 'unknown');
      $output[] = "- **PHP:** " . ($drupal['php']['version'] ?? 'unknown');

      if (!empty($drupal['database'])) {
        $db = $drupal['database'];
        $db_info = ($db['driver'] ?? 'unknown');
        if (!empty($db['version'])) {
          $db_info .= ' ' . $db['version'];
        }
        $output[] = "- **Database:** " . $db_info;
      }
      $output[] = "";

      // Modules.
      if (!empty($drupal['active_modules'])) {
        $modules = $drupal['active_modules'];
        $output[] = "## Modules (" . count($modules) . ")";

        // Group by origin.
        $by_origin = [];
        foreach ($modules as $module) {
          $origin = $module['origin'] ?? 'unknown';
          $by_origin[$origin][] = $module;
        }

        foreach (['core', 'contrib', 'custom'] as $origin) {
          if (empty($by_origin[$origin])) {
            continue;
          }

          $output[] = "\n### " . ucfirst($origin) . " (" .
            count($by_origin[$origin]) . ")";
          foreach ($by_origin[$origin] as $module) {
            $line = "- **{$module['label']}** (`{$module['name']}`)";
            if (!empty($module['version'])) {
              $line .= " - v{$module['version']}";
            }
            $output[] = $line;
          }
        }
        $output[] = "";
      }

      // Themes.
      if (!empty($drupal['themes'])) {
        $themes = $drupal['themes'];
        $output[] = "## Themes";
        $output[] = "- **Default:** " .
          ($themes['default'] ?? 'unknown');
        if (!empty($themes['admin'])) {
          $output[] = "- **Admin:** " . $themes['admin'];
        }
        $output[] = "";
      }
    }

    return implode("\n", $output);
  }

}
