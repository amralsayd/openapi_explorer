<?php

namespace Drupal\openapi_explorer_example\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\openapi_explorer_example\ExampleRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Sample controller-based API endpoints.
 *
 * These two routes exist to give OpenAPI Explorer something real to document.
 * The tags on this class docblock apply to every method in it, which is how
 * shared values are declared once rather than repeated per operation.
 *
 * @oapiCategory Example (controller)
 * @oapiTag example
 * @oapiResponse 400 ErrorResponse - A filter value was not usable on this site.
 * @oapiResponse 403 ErrorResponse - The account lacks the required permission.
 */
class ExampleApiController extends ControllerBase {

  /**
   * The sample repository.
   *
   * @var \Drupal\openapi_explorer_example\ExampleRepository
   */
  protected $repository;

  /**
   * Constructs the controller.
   *
   * @param \Drupal\openapi_explorer_example\ExampleRepository $repository
   *   The sample repository.
   */
  public function __construct(ExampleRepository $repository) {
    $this->repository = $repository;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('openapi_explorer_example.repository'));
  }

  /**
   * Lists published nodes, filtered by content type and tags.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Drupal\Core\Cache\CacheableJsonResponse
   *   A page of nodes.
   *
   * @oapiSummary List published nodes.
   * @oapiDescription Returns published nodes the current account may view,
   *   newest first, optionally narrowed by content type and by taxonomy terms.
   * @oapiOperationId listExampleNodes
   * @oapiParam query type string - Content type machine names, comma separated, for example "article,page".
   * @oapiParam query tags string - Taxonomy term ids or names, comma separated. A node matches when it references any of them.
   * @oapiParam query tags_field string - Which taxonomy reference field the tags filter applies to. Defaults to field_tags when the site has it.
   * @oapiParam query page integer - Zero-based page index.
   * @oapiParam query limit integer - Items per page, from 1 to 100. Defaults to 20.
   * @oapiResponse 200 NodeListResponse - A page of nodes.
   * @oapiExample 200 {"data":[{"id":12,"uuid":"6f1b...","type":"article","title":"Hello world","langcode":"en","created":"2024-05-01T09:30:00+00:00","changed":"2024-05-02T11:00:00+00:00","author":"editor","url":"/node/12","tags":[{"id":4,"name":"Announcements"}]}],"meta":{"total":57,"count":1,"page":0,"per_page":20,"pages":3}}
   */
  public function nodes(Request $request): CacheableJsonResponse {
    return $this->respond(
      function () use ($request) {
        return $this->repository->nodes($request->query->all());
      },
      'node_list',
      ['type', 'tags', 'tags_field', 'page', 'limit']
    );
  }

  /**
   * Lists user accounts, filtered by role.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Drupal\Core\Cache\CacheableJsonResponse
   *   A page of accounts.
   *
   * @oapiSummary List user accounts.
   * @oapiDescription Returns the accounts the current user may view, newest
   *   first, optionally narrowed by role. The anonymous user is never included,
   *   and no email addresses are exposed.
   * @oapiOperationId listExampleUsers
   * @oapiParam query roles string - Role machine names, comma separated, for example "editor,content_manager". An account matches when it has any of them.
   * @oapiParam query status boolean - Filter on the account status: true for active, false for blocked.
   * @oapiParam query page integer - Zero-based page index.
   * @oapiParam query limit integer - Items per page, from 1 to 100. Defaults to 20.
   * @oapiResponse 200 UserListResponse - A page of accounts.
   * @oapiExample 200 {"data":[{"id":7,"uuid":"c22a...","name":"editor","display_name":"editor","status":true,"created":"2023-11-14T08:12:00+00:00","roles":["authenticated","editor"]}],"meta":{"total":3,"count":1,"page":0,"per_page":20,"pages":1}}
   */
  public function users(Request $request): CacheableJsonResponse {
    return $this->respond(
      function () use ($request) {
        return $this->repository->users($request->query->all());
      },
      'user_list',
      ['roles', 'status', 'page', 'limit']
    );
  }

  /**
   * Runs a listing and renders it, or its failure, as JSON.
   *
   * These routes always answer with JSON, so a rejected filter is reported in
   * the documented error shape rather than as Drupal's themed HTML error page,
   * which is what a caller would otherwise receive.
   *
   * @param callable $listing
   *   Returns the payload, or throws BadRequestHttpException.
   * @param string $list_tag
   *   The entity list cache tag to invalidate on.
   * @param string[] $parameters
   *   The query parameters the response varies by.
   *
   * @return \Drupal\Core\Cache\CacheableJsonResponse
   *   The response.
   */
  protected function respond(callable $listing, string $list_tag, array $parameters): CacheableJsonResponse {
    try {
      $payload = $listing();
      $status = 200;
    }
    catch (BadRequestHttpException $exception) {
      $payload = ['message' => $exception->getMessage(), 'errors' => []];
      $status = 400;
    }
    $response = new CacheableJsonResponse($payload, $status);
    $response->addCacheableDependency($this->listCacheability($list_tag, $parameters));
    return $response;
  }

  /**
   * Cacheability shared by both listings.
   *
   * The response varies by the query parameters that filter it and by what the
   * current account is allowed to see.
   *
   * @param string $list_tag
   *   The entity list cache tag to invalidate on.
   * @param string[] $parameters
   *   The query parameters the response varies by.
   *
   * @return \Drupal\Core\Cache\CacheableMetadata
   *   The cacheable metadata.
   */
  protected function listCacheability(string $list_tag, array $parameters): CacheableMetadata {
    $cacheability = new CacheableMetadata();
    $cacheability->addCacheTags([$list_tag]);
    $contexts = ['user.permissions'];
    foreach ($parameters as $parameter) {
      $contexts[] = 'url.query_args:' . $parameter;
    }
    $cacheability->addCacheContexts($contexts);
    return $cacheability;
  }

}
