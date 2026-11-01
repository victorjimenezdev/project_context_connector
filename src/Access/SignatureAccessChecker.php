<?php

declare(strict_types=1);

namespace Drupal\project_context_connector\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\project_context_connector\Service\SignatureValidator;
use Symfony\Component\HttpFoundation\Request;

/**
 * Grants access to the signed route for a valid HMAC signature only.
 *
 * The route is GET-only, so OPTIONS never reaches this checker.
 */
final class SignatureAccessChecker implements AccessInterface {

  public function __construct(
    private readonly SignatureValidator $signatureValidator,
  ) {}

  /**
   * Checks the request signature.
   */
  public function access(Request $request): AccessResultInterface {
    if ($this->signatureValidator->isValid($request)) {
      return AccessResult::allowed()->setCacheMaxAge(0);
    }
    return AccessResult::forbidden('Invalid or missing HMAC signature.')->setCacheMaxAge(0);
  }

}
