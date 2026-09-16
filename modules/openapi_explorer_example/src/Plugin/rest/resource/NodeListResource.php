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
 * Sample REST resource listing published nodes.
 *
 * The documentation tags live on the get() method rather than on this class
 * docblock, which the REST plugin annotation already occupies.
 *
 * @RestResource(
 *   id = "openapi_explorer_example_nodes",
 *   label = @Translation("Example: node list"),
 *   uri_paths = {
 *     "canonical" = "/api/example/rest/nodes"
 *   }
 * )
 */
class NodeListResource extends ResourceBase {

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
   * Returns a page of published nodes.
   *
   * @return \Drupal\rest\ResourceResponse
   *   A page of nodes.
   *
   * @oapiSummary List published nodes.
   * @oapiDescription The REST resource equivalent of /api/example/nodes. Same
   *   filters, same response shape, served through the core REST module so the
   *   configured authentication providers and serialization formats apply.
   * @oapiCategory Example (REST)
   * @oapiTag example
   * @oapiOperationId restListExampleNodes
   * @oapiParam query type string - Content type machine names, comma separated, for example "article,page".
   * @oapiParam query tags string - Taxonomy term ids or names, comma separated. A node matches when it references any of them.
   * @oapiParam query tags_field string - Which taxonomy reference field the tags filter applies to. Defaults to field_tags when the site has it.
   * @oapiParam query page integer - Zero-based page index.
   * @oapiParam query limit integer - Items per page, from 1 to 100. Defaults to 20.
   * @oapiParam query _format string(json) - The core REST response format.
   * @oapiResponse 200 NodeListResponse - A page of nodes.
   * @oapiResponse 400 ErrorResponse - A filter value was not usable on this site.
   * @oapiResponse 403 ErrorResponse - The account lacks the "restful get openapi_explorer_example_nodes" permission.
   */
  public function get(): ResourceResponse {
    $request = $this->requestStack->getCurrentRequest();
    $query = $request ? $request->query->all() : [];

    $response = new ResourceResponse($this->repository->nodes($query), 200);
    $cacheability = new CacheableMetadata();
    $cacheability->addCacheTags(['node_list']);
    $cacheability->addCacheContexts([
      'user.permissions',
      'url.query_args:type',
      'url.query_args:tags',
      'url.query_args:tags_field',
      'url.query_args:page',
      'url.query_args:limit',
    ]);
    $response->addCacheableDependency($cacheability);
    return $response;
  }

}
