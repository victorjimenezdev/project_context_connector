<?php

/**
 * @file
 * Provides PHPStan stubs for Update Manager functions.
 */

declare(strict_types=1);

/**
 * Returns available project update data.
 *
 * This function is only a stub for static analysis and is not used at runtime.
 *
 * @param bool $refresh
 *   Whether to refresh available data.
 *
 * @return array<string,mixed>
 *   Project data.
 */
function update_get_available(bool $refresh = FALSE): array {
  return [];
}

/**
 * Calculates structured project update data.
 *
 * This function is only a stub for static analysis and is not used at runtime.
 *
 * @param array<string,mixed> $projects
 *   Raw project data from update_get_available().
 *
 * @return array<string,mixed>
 *   Calculated project data.
 */
function update_calculate_project_data(array $projects): array {
  return [];
}
