<?php

declare(strict_types=1);

namespace Drupal\project_context_connector\Update;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\State\StateInterface;
use Drupal\update\UpdateFetcherInterface;
use Drupal\update\UpdateManagerInterface;

/**
 * Reads cached Update Manager data without fetching or queueing.
 *
 * This does not call update_get_available(), even with FALSE: that function
 * creates fetch tasks for missing data, and Drupal 11.5 deprecates it. It reads
 * the stored releases and uses the calculation API of the running core.
 */
class UpdateStatusCollector {

  /**
   * Every status that normalize() can return.
   */
  public const array STATUSES = [
    'current',
    'update_available',
    'security_update_available',
    'not_supported',
    'revoked',
    'not_fetched',
    'unknown',
  ];

  private const string RELEASES_COLLECTION = 'update_available_releases';

  public function __construct(
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly KeyValueExpirableFactoryInterface $keyValueExpirable,
    private readonly StateInterface $state,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
    private readonly LoggerChannelInterface $logger,
    private readonly ?object $updateManager = NULL,
  ) {}

  /**
   * Collects cached update data.
   *
   * @return array{module_present: bool, data_state: string, last_checked: int|null, age_seconds: int|null, stale_after_seconds: int|null, statuses: array<string, string>}
   *   The data state is unavailable, missing, stale or current.
   */
  public function collect(): array {
    $result = [
      'module_present' => FALSE,
      'data_state' => 'unavailable',
      'last_checked' => NULL,
      'age_seconds' => NULL,
      'stale_after_seconds' => NULL,
      'statuses' => [],
    ];
    if (!$this->moduleHandler->moduleExists('update') || $this->updateManager === NULL) {
      return $result;
    }

    $result['module_present'] = TRUE;
    $result['data_state'] = 'missing';
    $intervalDays = (int) $this->configFactory->get('update.settings')->get('check.interval_days');
    $result['stale_after_seconds'] = max(1, $intervalDays) * 86400;
    $lastChecked = $this->state->get('update.last_check');
    if (is_int($lastChecked) && $lastChecked > 0) {
      $result['last_checked'] = $lastChecked;
      $result['age_seconds'] = max(0, $this->time->getRequestTime() - $lastChecked);
    }

    $available = $this->keyValueExpirable->get(self::RELEASES_COLLECTION)->getAll();
    if ($available === []) {
      return $result;
    }
    $isStale = $result['age_seconds'] === NULL || $result['age_seconds'] > $result['stale_after_seconds'];
    $result['data_state'] = $isStale ? 'stale' : 'current';

    try {
      foreach ($this->calculate($available) as $project => $data) {
        if (is_array($data) && is_int($data['status'] ?? NULL)) {
          $result['statuses'][(string) $project] = self::normalize($data['status']);
        }
      }
    }
    catch (\Throwable $e) {
      $result['data_state'] = 'unavailable';
      $result['statuses'] = [];
      $this->logger->notice('Cached update status is unavailable: @type', ['@type' => get_class($e)]);
    }
    return $result;
  }

  /**
   * Calculates project data with the API of the running core.
   *
   * @param array<string, mixed> $available
   *   The stored release data.
   *
   * @return array<mixed>
   *   The project data.
   */
  private function calculate(array $available): array {
    // Drupal 11.5 and later provide a service method.
    if ($this->updateManager !== NULL && method_exists($this->updateManager, 'calculateProjectData')) {
      return $this->updateManager->calculateProjectData($available);
    }
    $this->moduleHandler->loadInclude('update', 'inc', 'update.compare');
    return update_calculate_project_data($available);
  }

  /**
   * Maps an Update Manager status code to a stable string.
   */
  public static function normalize(int $status): string {
    return match ($status) {
      UpdateManagerInterface::CURRENT => 'current',
      UpdateManagerInterface::NOT_CURRENT => 'update_available',
      UpdateManagerInterface::NOT_SECURE => 'security_update_available',
      UpdateManagerInterface::NOT_SUPPORTED => 'not_supported',
      UpdateManagerInterface::REVOKED => 'revoked',
      UpdateFetcherInterface::NOT_FETCHED => 'not_fetched',
      default => 'unknown',
    };
  }

}
