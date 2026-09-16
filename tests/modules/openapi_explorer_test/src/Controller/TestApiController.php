<?php

namespace Drupal\openapi_explorer_test\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Annotated routes used by the OpenAPI Explorer tests.
 *
 * @oapiCategory Testing
 * @oapiTag fixtures
 */
class TestApiController {

  /**
   * Lists items.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The item list.
   *
   * @oapiSummary List the available items.
   * @oapiDescription Returns every item, filtered by the given status.
   * @oapiParam query status string(draft|published) - Filter by status.
   * @oapiRequestField data.items[].label string required - The item label.
   * @oapiResponse 200 PaginatedResponse - A page of items.
   * @oapiResponse 403 ErrorResponse - Access denied.
   */
  public function items(Request $request): JsonResponse {
    $page = $request->query->get('page');
    return new JsonResponse(['data' => [], 'meta' => ['page' => $page]]);
  }

  /**
   * Returns one item.
   *
   * @param string $item
   *   The item id.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The item.
   *
   * @oapiSummary Return a single item.
   * @oapiParam path item integer required - The item id.
   * @oapiDeprecated Use the collection endpoint with a filter instead.
   * @oapiOperationId getTestItem
   * @oapiSecurity none
   * @oapiResponse 200 - The item.
   * @oapiResponseField id integer - The item id.
   */
  public function item(string $item): JsonResponse {
    return new JsonResponse(['id' => $item]);
  }

  /**
   * An endpoint that must never appear in the documentation.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   An empty response.
   *
   * @oapiInternal
   * @oapiSummary This should never be published.
   */
  public function internalCallback(): JsonResponse {
    return new JsonResponse([]);
  }

  /**
   * An endpoint with no annotations at all.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   An empty response.
   */
  public function bare(): JsonResponse {
    return new JsonResponse([]);
  }

}
