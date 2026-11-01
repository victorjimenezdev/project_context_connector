<?php

declare(strict_types=1);

namespace Drupal\project_context_connector_tool\Plugin\tool\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\project_context_connector\Service\ContextSnapshotter;
use Drupal\tool\Attribute\Tool;
use Drupal\tool\ExecutableResult;
use Drupal\tool\FailureCategory;
use Drupal\tool\Tool\ToolBase;
use Drupal\tool\Tool\ToolOperation;
use Drupal\tool\TypedData\InputDefinition;
use Drupal\tool\TypedData\MapOutputDefinition;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Returns the structured snapshot that HTTP and Drush clients receive.
 *
 * ToolBase enforces the declared permission for the acting account.
 */
#[Tool(
  id: 'project_context_snapshot',
  label: new TranslatableMarkup('Get Project Snapshot'),
  description: new TranslatableMarkup('Returns sanitized, read-only project context. Site exposure settings apply.'),
  operation: ToolOperation::Read,
  input_definitions: [
    'include_security_updates' => new InputDefinition(
      data_type: 'boolean',
      label: new TranslatableMarkup('Include security updates'),
      description: new TranslatableMarkup('Set FALSE to leave out update data. TRUE cannot show update data that the site settings redact.'),
      required: FALSE,
      default_value: TRUE,
    ),
  ],
  output_definitions: [
    'snapshot' => new MapOutputDefinition(
      label: new TranslatableMarkup('Snapshot'),
      description: new TranslatableMarkup('The snapshot, as described by schema/snapshot.v2.schema.json.'),
    ),
  ],
  permission: 'access project context snapshot',
)]
final class ProjectSnapshot extends ToolBase {

  /**
   * The snapshot service.
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
  protected function doExecute(array $values): ExecutableResult {
    try {
      $snapshot = $this->snapshotter->buildSnapshot();
    }
    catch (\Throwable $e) {
      $this->logger->error('Snapshot generation failed: @type', ['@type' => get_class($e)]);
      return ExecutableResult::failure(
        new TranslatableMarkup('The project snapshot could not be generated.'),
        NULL,
        FailureCategory::Runtime,
      );
    }
    if (($values['include_security_updates'] ?? TRUE) === FALSE) {
      $snapshot = ContextSnapshotter::withoutUpdateData($snapshot);
    }
    return ExecutableResult::success(
      new TranslatableMarkup('Project snapshot generated.'),
      ['snapshot' => $snapshot],
    );
  }

}
