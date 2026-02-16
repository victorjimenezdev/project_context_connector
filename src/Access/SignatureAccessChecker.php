<?php

declare(strict_types=1);

namespace Drupal\project_context_connector\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\project_context_connector\Service\SignatureValidator;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Custom access checker for HMAC-signed routes.
 *
 * Validates request signatures and provides proper Drupal access checking
 * instead of relying solely on event subscribers. This ensures failed
 * signature attempts are properly logged in Drupal's access system.
 */
final class SignatureAccessChecker implements AccessInterface {

  /**
   * Constructor.
   *
   * @param \Drupal\project_context_connector\Service\SignatureValidator $signatureValidator
   *   The signature validator service.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack.
   */
  public function __construct(
    private readonly SignatureValidator $signatureValidator,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * Checks access based on HMAC signature validation.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account (not used, but required by interface).
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(AccountInterface $account): AccessResultInterface {
    $request = $this->requestStack->getCurrentRequest();

    // Allow OPTIONS preflight requests to pass.
    if ($request && $request->getMethod() === 'OPTIONS') {
      return AccessResult::allowed()->setCacheMaxAge(0);
    }

    // Validate HMAC signature.
    if ($this->signatureValidator->isValid()) {
      // Signature is valid. Don't cache this result as it's request-specific.
      return AccessResult::allowed()->setCacheMaxAge(0);
    }

    // Signature validation failed. This will now appear in Drupal logs.
    return AccessResult::forbidden('Invalid or missing HMAC signature.')
      ->setCacheMaxAge(0);
  }

}
