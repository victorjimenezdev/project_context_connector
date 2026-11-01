<?php

declare(strict_types=1);

namespace Drupal\project_context_connector\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Checks a CORS allow-list pattern.
 */
#[Constraint(
  id: 'PccAllowedOrigin',
  label: new TranslatableMarkup('Allowed CORS origin pattern', [], ['context' => 'Validation']),
)]
final class AllowedOriginConstraint extends SymfonyConstraint {

  /**
   * The violation message.
   */
  public string $message = 'The origin pattern %value must be scheme://host with an optional port and an optional leading *. on the host.';

}
