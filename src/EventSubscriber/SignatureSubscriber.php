<?php

declare(strict_types=1);

namespace Drupal\project_context_connector\EventSubscriber;

use Drupal\project_context_connector\Service\SignatureValidator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Enforces HMAC on the signed snapshot route.
 */
final class SignatureSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly SignatureValidator $validator,
  ) {}

  /**
   *
   */
  public static function getSubscribedEvents(): array {
    // Run after routing so _route is available. Default priority is fine.
    return [
      KernelEvents::REQUEST => ['onRequest', 0],
    ];
  }

  /**
   *
   */
  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    $request = $event->getRequest();
    $route = (string) $request->attributes->get('_route', '');

    if ($route !== 'project_context_connector.snapshot_signed') {
      return;
    }

    // Allow preflight to pass.
    if ($request->getMethod() === 'OPTIONS') {
      return;
    }

    if (!$this->validator->isValid()) {
      $res = new JsonResponse(['message' => 'Forbidden: invalid signature.'], 403);
      // Defensive header.
      $res->headers->set('X-Content-Type-Options', 'nosniff');
      $event->setResponse($res);
    }
  }

}
