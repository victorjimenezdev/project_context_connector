<?php

declare(strict_types=1);

namespace Drupal\project_context_connector\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\project_context_connector\Service\ContextSnapshotter;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Returns the read-only project context snapshot.
 *
 * The responses are plain JSON responses, so Dynamic Page Cache stores nothing.
 * The Cache-Control header only allows a private client copy.
 */
final class SnapshotController implements ContainerInjectionInterface {

  public function __construct(
    private readonly ContextSnapshotter $snapshotter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('project_context_connector.context_snapshotter'));
  }

  /**
   * Standard endpoint: the private client copy follows cache_max_age.
   */
  public function standard(): JsonResponse {
    $data = $this->snapshotter->buildSnapshot();
    $maxAge = $data['_meta']['cache']['max_age'];
    $response = new JsonResponse($data);
    $response->headers->set('Cache-Control', $maxAge > 0 ? 'private, max-age=' . $maxAge : 'private, no-store');
    $response->setVary(['Authorization', 'Cookie'], FALSE);
    return $response;
  }

  /**
   * Signed endpoint: a signature is short-lived, so no copy is allowed.
   */
  public function signed(): JsonResponse {
    $data = $this->snapshotter->buildSnapshot();
    $data['_meta']['cache']['max_age'] = 0;
    $response = new JsonResponse($data);
    $response->headers->set('Cache-Control', 'private, no-store');
    return $response;
  }

}
