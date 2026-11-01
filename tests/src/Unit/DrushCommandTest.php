<?php

declare(strict_types=1);

namespace Drupal\Tests\project_context_connector\Unit;

use Drupal\project_context_connector\Drush\Commands\ProjectContextConnectorCommands;
use Drupal\project_context_connector\Service\ContextSnapshotter;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests Drush output and its failure exit code.
 */
#[Group('project_context_connector')]
final class DrushCommandTest extends UnitTestCase {

  /**
   * Tests that the command declares its name, alias and option.
   */
  public function testDefinition(): void {
    $command = $this->command([]);
    $this->assertSame('pcc:snapshot', $command->getName());
    $this->assertSame(['pcc'], $command->getAliases());
    $this->assertTrue($command->getDefinition()->hasOption('pretty'));
  }

  /**
   * Tests minified and pretty JSON output.
   */
  public function testJsonOutput(): void {
    $tester = new CommandTester($this->command(['a' => '/b']));

    $this->assertSame(Command::SUCCESS, $tester->execute([]));
    $this->assertSame('{"a":"/b"}', trim($tester->getDisplay()));

    $this->assertSame(Command::SUCCESS, $tester->execute(['--pretty' => TRUE]));
    $this->assertStringContainsString("\n", trim($tester->getDisplay()));
    $this->assertSame(['a' => '/b'], json_decode($tester->getDisplay(), TRUE, 512, JSON_THROW_ON_ERROR));
  }

  /**
   * Tests that a serialization failure exits nonzero and prints no JSON.
   */
  public function testSerializationFailure(): void {
    $tester = new CommandTester($this->command(['a' => NAN]));

    $this->assertSame(Command::FAILURE, $tester->execute([], ['capture_stderr_separately' => TRUE]));
    $this->assertSame('', $tester->getDisplay());
    $this->assertStringContainsString('could not be encoded', $tester->getErrorOutput());
  }

  /**
   * Builds the command around a fixed snapshot.
   *
   * @param array<string, mixed> $snapshot
   *   The snapshot to return.
   */
  private function command(array $snapshot): ProjectContextConnectorCommands {
    $snapshotter = $this->createStub(ContextSnapshotter::class);
    $snapshotter->method('buildSnapshot')->willReturn($snapshot);
    return new ProjectContextConnectorCommands($snapshotter);
  }

}
