<?php

declare(strict_types=1);

namespace Drupal\project_context_connector\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\project_context_connector\Service\OriginValidator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Adds CORS headers for allow-listed origins and handles preflight.
 */
final class CorsSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly OriginValidator $originValidator,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::REQUEST => ['onRequest', 40],
      KernelEvents::RESPONSE => ['onResponse', -40],
    ];
  }

  /**
   * On request method.
   */
  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    $request = $event->getRequest();
    $route = $request->attributes->get('_route');
    if ($route !== 'project_context_connector.snapshot') {
      return;
    }
    $conf = $this->configFactory->get('project_context_connector.settings');
    if (!$conf->get('enable_cors')) {
      return;
    }

    // Handle CORS preflight for GET.
    if ($request->getMethod() === 'OPTIONS') {
      $allowed = $this->originValidator->allowedOriginFor($request);
      if ($allowed !== NULL) {
        $response = new Response('', 204);
        $response->headers->set('Access-Control-Allow-Origin', $allowed);
        $response->headers->set('Vary', 'Origin');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization');
        $event->setResponse($response);
      }
      else {
        $event->setResponse(new Response('', 403));
      }
    }
  }

  /**
   * On reponse method.
   */
  public function onResponse(ResponseEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    $request = $event->getRequest();
    $route = $request->attributes->get('_route');
    if ($route !== 'project_context_connector.snapshot') {
      return;
    }

    $conf = $this->configFactory->get('project_context_connector.settings');
    if (!$conf->get('enable_cors')) {
      return;
    }

    $allowed = $this->originValidator->allowedOriginFor($request);
    if ($allowed !== NULL) {
      $response = $event->getResponse();
      $response->headers->set('Access-Control-Allow-Origin', $allowed);
      $response->headers->set('Vary', 'Origin');
    }
  }

}
