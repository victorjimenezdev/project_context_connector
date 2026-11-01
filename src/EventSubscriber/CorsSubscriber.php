<?php

declare(strict_types=1);

namespace Drupal\project_context_connector\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\project_context_connector\Service\OriginValidator;
use Drupal\project_context_connector\SnapshotPaths;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Handles CORS for the standard snapshot route.
 *
 * Preflight is answered before routing, so it needs no payload access and it
 * grants nothing beyond the allow-list. Credentialed CORS is not offered. The
 * signed route is for server clients and never receives CORS headers.
 */
final class CorsSubscriber implements EventSubscriberInterface {

  private const array ALLOWED_REQUEST_HEADERS = ['accept', 'authorization', 'content-type'];

  public function __construct(
    private readonly OriginValidator $originValidator,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      // Core OptionsRequestSubscriber runs at 1000 and answers every OPTIONS.
      KernelEvents::REQUEST => ['onRequest', 1001],
      KernelEvents::RESPONSE => ['onResponse', -40],
    ];
  }

  /**
   * Answers a CORS preflight for the standard route.
   */
  public function onRequest(RequestEvent $event): void {
    $request = $event->getRequest();
    if (!$event->isMainRequest()
      || $request->getMethod() !== 'OPTIONS'
      || !$request->headers->has('Origin')
      || !$request->headers->has('Access-Control-Request-Method')
      || !SnapshotPaths::isStandard($request->getPathInfo())
      || !$this->corsEnabled()) {
      return;
    }

    $response = new Response('', 403);
    $response->setVary(['Origin', 'Access-Control-Request-Method', 'Access-Control-Request-Headers']);
    $allowed = $this->originValidator->allowedOriginFor($request);
    $requested = array_filter(array_map(
      static fn(string $header): string => strtolower(trim($header)),
      explode(',', (string) $request->headers->get('Access-Control-Request-Headers', '')),
    ));
    if ($allowed !== NULL
      && $request->headers->get('Access-Control-Request-Method') === 'GET'
      && array_diff($requested, self::ALLOWED_REQUEST_HEADERS) === []) {
      $response->setStatusCode(204);
      $response->headers->set('Access-Control-Allow-Origin', $allowed);
      $response->headers->set('Access-Control-Allow-Methods', 'GET');
      $response->headers->set('Access-Control-Allow-Headers', implode(', ', self::ALLOWED_REQUEST_HEADERS));
      $response->headers->set('Access-Control-Max-Age', '600');
    }
    $event->setResponse($response);
  }

  /**
   * Adds CORS headers to standard route responses.
   */
  public function onResponse(ResponseEvent $event): void {
    $request = $event->getRequest();
    if (!$event->isMainRequest()
      || $request->attributes->get('_route') !== SnapshotPaths::STANDARD_ROUTE
      || !$this->corsEnabled()) {
      return;
    }
    $response = $event->getResponse();
    $response->setVary('Origin', FALSE);
    $allowed = $this->originValidator->allowedOriginFor($request);
    if ($allowed !== NULL) {
      $response->headers->set('Access-Control-Allow-Origin', $allowed);
      $response->headers->set('Access-Control-Expose-Headers', 'Retry-After');
    }
  }

  /**
   * Whether CORS is enabled.
   */
  private function corsEnabled(): bool {
    return (bool) $this->configFactory->get('project_context_connector.settings')->get('enable_cors');
  }

}
