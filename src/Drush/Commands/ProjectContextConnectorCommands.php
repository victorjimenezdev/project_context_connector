<?php

declare(strict_types=1);

namespace Drupal\project_context_connector\Drush\Commands;

use Drupal\project_context_connector\Service\ContextSnapshotter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Prints the project context snapshot as JSON.
 */
#[AsCommand(
  name: 'pcc:snapshot',
  description: 'Prints the project context snapshot as JSON.',
  aliases: ['pcc'],
)]
final class ProjectContextConnectorCommands extends Command {

  public function __construct(
    private readonly ContextSnapshotter $snapshotter,
  ) {
    parent::__construct();
  }

  /**
   * Creates the command with its dependencies.
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('project_context_connector.context_snapshotter'));
  }

  /**
   * {@inheritdoc}
   */
  protected function configure(): void {
    $this
      ->addOption('pretty', NULL, InputOption::VALUE_NONE, 'Output pretty-printed JSON.')
      ->addUsage('pcc:snapshot')
      ->addUsage('pcc:snapshot --pretty');
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | ($input->getOption('pretty') ? JSON_PRETTY_PRINT : 0);
    try {
      $json = json_encode($this->snapshotter->buildSnapshot(), $flags);
    }
    catch (\JsonException) {
      $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
      $errors->writeln('<error>The snapshot could not be encoded as JSON.</error>');
      return Command::FAILURE;
    }
    $output->writeln($json, OutputInterface::OUTPUT_RAW);
    return Command::SUCCESS;
  }

}
