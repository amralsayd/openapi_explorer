<?php

namespace Drupal\openapi_explorer_example\Plugin\rest\resource;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\openapi_explorer_example\ExampleRepository;
use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Sample REST resource listing user accounts.
 *
 * The documentation tags live on the get() method rather than on this class
 * docblock, which the REST plugin annotation already occupies.
 *
 * @RestResource(
 *   id = "openapi_explorer_example_users",
 *   label = @Translation("Example: user list"),
 *   uri_paths = {
 *     "canonical" = "/api/example/rest/users"
 *   }
 * )
 */
class UserListResource extends ResourceBase {

  /**
   * The sample repository.
   *
   * @var \Drupal\openapi_explorer_example\ExampleRepository
   */
  protected $repository;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * Constructs the resource.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin id.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param array $serializer_formats
   *   The available serialization formats.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   * @param \Drupal\openapi_explorer_example\ExampleRepository $repository
   *   The sample repository.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, array $serializer_formats, LoggerInterface $logger, ExampleRepository $repository, RequestStack $request_stack) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $serializer_formats, $logger);
    $this->repository = $repository;
    $this->requestStack = $request_stack;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->getParameter('serializer.formats'),
      $container->get('logger.factory')->get('rest'),
      $container->get('openapi_explorer_example.repository'),
      $container->get('request_stack')
    );
  }

  /**
   * Returns a page of user accounts.
   *
   * @return \Drupal\rest\ResourceResponse
   *   A page of accounts.
   *
   * @oapiSummary List user accounts.
   * @oapiDescription The REST resource equivalent of /api/example/users. The
   *   anonymous user is never included, and no email addresses are exposed.
   * @oapiCategory Example (REST)
   * @oapiTag example
   * @oapiOperationId restListExampleUsers
   * @oapiParam query roles string - Role machine names, comma separated, for example "editor,content_manager". An account matches when it has any of them.
   * @oapiParam query status boolean - Filter on the account status: true for active, false for blocked.
   * @oapiParam query page integer - Zero-based page index.
   * @oapiParam query limit integer - Items per page, from 1 to 100. Defaults to 20.
   * @oapiParam query _format string(json) - The core REST response format.
   * @oapiResponse 200 UserListResponse - A page of accounts.
   * @oapiResponse 400 ErrorResponse - A filter value was not usable on this site.
   * @oapiResponse 403 ErrorResponse - The account lacks the "restful get openapi_explorer_example_users" permission.
   */
  public function get(): ResourceResponse {
    $request = $this->requestStack->getCurrentRequest();
    $query = $request ? $request->query->all() : [];

    $response = new ResourceResponse($this->repository->users($query), 200);
    $cacheability = new CacheableMetadata();
    $cacheability->addCacheTags(['user_list']);
    $cacheability->addCacheContexts([
      'user.permissions',
      'url.query_args:roles',
      'url.query_args:status',
      'url.query_args:page',
      'url.query_args:limit',
    ]);
    $response->addCacheableDependency($cacheability);
    return $response;
  }

}
