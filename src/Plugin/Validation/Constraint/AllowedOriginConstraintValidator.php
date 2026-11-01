<?php

declare(strict_types=1);

namespace Drupal\project_context_connector\Plugin\Validation\Constraint;

use Drupal\project_context_connector\Service\OriginValidator;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the PccAllowedOrigin constraint.
 */
final class AllowedOriginConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    assert($constraint instanceof AllowedOriginConstraint);
    if ($value === NULL || $value === '') {
      return;
    }
    if (!is_string($value) || !OriginValidator::isValidPattern($value)) {
      $this->context->addViolation($constraint->message, ['%value' => is_scalar($value) ? (string) $value : gettype($value)]);
    }
  }

}
