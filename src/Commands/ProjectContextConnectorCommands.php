<?php

declare(strict_types=1);

namespace Drupal\project_context_connector\Commands;

use Drupal\project_context_connector\Service\ContextSnapshotter;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for Project Context Connector.
 */
final class ProjectContextConnectorCommands extends DrushCommands {

  public function __construct(
    private readonly ContextSnapshotter $snapshotter,
  ) {
    parent::__construct();
  }

  /**
   * Print the project context snapshot as JSON.
   *
   * @param array $options
   *   Options.
   *
   * @option pretty
   *   Output pretty-printed JSON.
   *
   * @command pcc:snapshot
   * @aliases pcc
   * @usage drush pcc:snapshot
   *   Output minified JSON snapshot.
   * @usage drush pcc:snapshot --pretty
   *   Output pretty-printed JSON snapshot.
   */
  #[CLI\Command(name: 'pcc:snapshot', aliases: ['pcc'])]
  #[CLI\Option(name: 'pretty', description: 'Output pretty-printed JSON.')]
  public function snapshot(array $options = ['pretty' => FALSE]): int {
    $data = $this->snapshotter->buildSnapshot();
    $flags = 0;
    if (!empty($options['pretty'])) {
      $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;
    }
    $this->output()->writeln((string) json_encode($data, $flags));
    return self::EXIT_SUCCESS;
  }

}
