<?php

declare(strict_types=1);

namespace Drupal\project_context_connector\EventSubscriber;

use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Config\ConfigImporterEvent;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Applies the typed settings constraints to configuration imports.
 *
 * Core does not validate simple configuration during import by itself.
 */
final class ConfigImportSubscriber implements EventSubscriberInterface {

  private const string CONFIG_NAME = 'project_context_connector.settings';

  public function __construct(
    private readonly TypedConfigManagerInterface $typedConfig,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [ConfigEvents::IMPORT_VALIDATE => ['onImportValidate']];
  }

  /**
   * Reports each constraint violation in the incoming settings.
   */
  public function onImportValidate(ConfigImporterEvent $event): void {
    $importer = $event->getConfigImporter();
    $data = $importer->getStorageComparer()->getSourceStorage()->read(self::CONFIG_NAME);
    if (!is_array($data)) {
      return;
    }
    $violations = $this->typedConfig->createFromNameAndData(self::CONFIG_NAME, $data)->validate();
    foreach ($violations as $violation) {
      $importer->logError(sprintf(
        '%s: %s: %s',
        self::CONFIG_NAME,
        $violation->getPropertyPath(),
        (string) $violation->getMessage(),
      ));
    }
  }

}
