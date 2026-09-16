<?php

namespace Drupal\openapi_explorer\OpenApi;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\openapi_explorer\Doc\ApiDocProviderInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Builds an OpenAPI 3 model for every endpoint the site exposes.
 *
 * The endpoint list is self-discovered from the router and REST plugins by
 * EndpointDiscovery, so the module is standalone. Per endpoint it merges:
 *   - the discovered row (path, methods, auth providers, owning module);
 *   - EndpointReflector (backing class/method, path parameters, scanned query
 *     keys);
 *   - DocAnnotationParser (docblock tags: summary, parameters, request and
 *     response fields, referenced components, and the operation flags).
 *
 * It exposes both a render-ready per-endpoint fragment (consumed by the
 * documentation page and, through ApiDocProviderInterface, by any other module)
 * and a raw OpenAPI 3.0 document, serialisable to JSON or YAML for external
 * tooling.
 */
class OpenApiBuilder implements ApiDocProviderInterface {

  /**
   * HTTP verbs documented, in display order.
   */
  const VERBS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

  /**
   * Verbs that carry a request body.
   */
  const WRITE_VERBS = ['POST', 'PUT', 'PATCH'];

  /**
   * The endpoint discovery service.
   *
   * @var \Drupal\openapi_explorer\OpenApi\EndpointDiscovery
   */
  protected $discovery;

  /**
   * The endpoint reflector.
   *
   * @var \Drupal\openapi_explorer\OpenApi\EndpointReflector
   */
  protected $reflector;

  /**
   * The docblock annotation parser.
   *
   * @var \Drupal\openapi_explorer\OpenApi\DocAnnotationParser
   */
  protected $parser;

  /**
   * The schema component catalog.
   *
   * @var \Drupal\openapi_explorer\OpenApi\SchemaComponents
   */
  protected $components;

  /**
   * The security scheme resolver.
   *
   * @var \Drupal\openapi_explorer\OpenApi\AuthSchemeResolver
   */
  protected $authSchemes;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Built endpoint fragments keyed by endpoint id (lazy cache).
   *
   * @var array|null
   */
  protected $endpoints = NULL;

  /**
   * Class-level annotation models keyed by class name (lazy cache).
   *
   * @var array
   */
  protected $classModels = [];

  /**
   * The built specification (lazy cache).
   *
   * @var array|null
   */
  protected $spec = NULL;

  /**
   * Constructs the builder.
   *
   * @param \Drupal\openapi_explorer\OpenApi\EndpointDiscovery $discovery
   *   The endpoint discovery service.
   * @param \Drupal\openapi_explorer\OpenApi\EndpointReflector $reflector
   *   The endpoint reflector.
   * @param \Drupal\openapi_explorer\OpenApi\DocAnnotationParser $parser
   *   The docblock annotation parser.
   * @param \Drupal\openapi_explorer\OpenApi\SchemaComponents $components
   *   The schema component catalog.
   * @param \Drupal\openapi_explorer\OpenApi\AuthSchemeResolver $auth_schemes
   *   The security scheme resolver.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(EndpointDiscovery $discovery, EndpointReflector $reflector, DocAnnotationParser $parser, SchemaComponents $components, AuthSchemeResolver $auth_schemes, ModuleHandlerInterface $module_handler, ConfigFactoryInterface $config_factory) {
    $this->discovery = $discovery;
    $this->reflector = $reflector;
    $this->parser = $parser;
    $this->components = $components;
    $this->authSchemes = $auth_schemes;
    $this->moduleHandler = $module_handler;
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public function forEndpoint(string $endpointId): ?array {
    $this->ensureBuilt();
    return $this->endpoints[$endpointId] ?? NULL;
  }

  /**
   * All endpoint fragments, in discovery order.
   *
   * @return array[]
   *   List of endpoint fragments.
   */
  public function endpoints(): array {
    $this->ensureBuilt();
    return array_values($this->endpoints);
  }

  /**
   * The annotation parser, so consumers can render the configured tag names.
   *
   * @return \Drupal\openapi_explorer\OpenApi\DocAnnotationParser
   *   The parser.
   */
  public function parser(): DocAnnotationParser {
    return $this->parser;
  }

  /**
   * Endpoint fragments grouped by owning module.
   *
   * @return array
   *   Map of module name to a list of fragments.
   */
  public function endpointsByModule(): array {
    $byModule = [];
    foreach ($this->endpoints() as $endpoint) {
      $module = $endpoint['module'] !== '' ? $endpoint['module'] : '(unknown)';
      $byModule[$module][] = $endpoint;
    }
    ksort($byModule);
    return $byModule;
  }

  /**
   * Endpoint fragments grouped by category, then by version subgroup.
   *
   * The category is the first path segment after any configured path prefix;
   * when that segment is a version (v1, v2, ...) it is treated as the version
   * and the category becomes the following segment. An explicit category
   * annotation always wins. This drives the documentation page sidebar.
   *
   * @return array[]
   *   Ordered list of groups, each with 'name', 'slug', 'count',
   *   'has_versions' and 'subgroups'.
   */
  public function endpointsByCategory(): array {
    $groups = [];
    foreach ($this->endpoints() as $endpoint) {
      [$pathName, $version] = $this->categoryForPath((string) ($endpoint['path'] ?? ''));
      $annotated = trim((string) ($endpoint['category'] ?? ''));
      $name = $annotated !== '' ? $annotated : $pathName;
      if (!isset($groups[$name])) {
        $groups[$name] = [
          'name' => $name,
          'slug' => $this->slug($name),
          'count' => 0,
          'subgroups' => [],
        ];
      }
      $key = $version !== '' ? $version : '_none';
      if (!isset($groups[$name]['subgroups'][$key])) {
        $groups[$name]['subgroups'][$key] = [
          'version' => $version,
          'slug' => $this->slug($name . '-' . ($version !== '' ? $version : 'default')),
          'endpoints' => [],
        ];
      }
      $seed = (string) ($endpoint['id'] !== '' ? $endpoint['id'] : $endpoint['path']);
      $endpoint['anchor'] = 'oae-ep-' . $this->slug($seed);
      // Sidebar label: drop the prefix already shown by the parent category.
      $endpoint['display_path'] = $this->displayPath((string) ($endpoint['path'] ?? ''));
      $groups[$name]['subgroups'][$key]['endpoints'][] = $endpoint;
      $groups[$name]['count']++;
    }
    ksort($groups);

    $result = [];
    foreach ($groups as $group) {
      // Order subgroups: no-version first, then v1, v2, ... naturally.
      uksort($group['subgroups'], function ($a, $b) {
        if ($a === '_none') {
          return -1;
        }
        if ($b === '_none') {
          return 1;
        }
        return strnatcasecmp($a, $b);
      });
      $group['has_versions'] = FALSE;
      foreach ($group['subgroups'] as $subgroup) {
        if ($subgroup['version'] !== '') {
          $group['has_versions'] = TRUE;
          break;
        }
      }
      $group['subgroups'] = array_values($group['subgroups']);
      $result[] = $group;
    }
    return $result;
  }

  /**
   * Aggregate coverage counts across endpoints and operations.
   *
   * @return array
   *   ['endpoints' => [...], 'operations' => [...], 'warnings' => int,
   *    'capped' => bool].
   */
  public function coverageSummary(): array {
    $endpoints = ['total' => 0, 'annotated' => 0, 'partial' => 0, 'none' => 0];
    $operations = ['total' => 0, 'annotated' => 0, 'partial' => 0];
    $warnings = 0;
    foreach ($this->endpoints() as $endpoint) {
      $endpoints['total']++;
      $level = $endpoint['coverage'] ?? 'none';
      $endpoints[$level] = ($endpoints[$level] ?? 0) + 1;
      foreach ($endpoint['operations'] as $operation) {
        $operations['total']++;
        $operationLevel = ($operation['coverage'] ?? 'partial') === 'annotated' ? 'annotated' : 'partial';
        $operations[$operationLevel]++;
        $warnings += count($operation['warnings'] ?? []);
      }
    }
    return [
      'endpoints' => $endpoints,
      'operations' => $operations,
      'warnings' => $warnings,
      'capped' => $this->discovery->isCapped(),
    ];
  }

  /**
   * Every validation warning raised while parsing annotations.
   *
   * @return array[]
   *   List of ['endpoint', 'path', 'method', 'message'].
   */
  public function warnings(): array {
    $warnings = [];
    foreach ($this->endpoints() as $endpoint) {
      foreach ($endpoint['operations'] as $verb => $operation) {
        foreach ($operation['warnings'] ?? [] as $message) {
          $warnings[] = [
            'endpoint' => $endpoint['id'],
            'path' => $endpoint['path'],
            'method' => $verb,
            'message' => $message,
          ];
        }
      }
    }
    return $warnings;
  }

  /**
   * Builds the full OpenAPI 3.0 specification array.
   *
   * @return array
   *   The OpenAPI document.
   */
  public function spec(): array {
    if ($this->spec !== NULL) {
      return $this->spec;
    }
    $paths = [];
    $usedSecurity = [];
    $usedTags = [];
    foreach ($this->endpoints() as $endpoint) {
      $path = $endpoint['path'];
      if ($path === '') {
        continue;
      }
      foreach ($endpoint['operations'] as $verb => $operation) {
        $item = $this->specOperation($endpoint, $verb, $operation);
        foreach ($item['security'] ?? [] as $requirement) {
          foreach (array_keys($requirement) as $name) {
            $usedSecurity[$name] = TRUE;
          }
        }
        foreach ($item['tags'] ?? [] as $tag) {
          $usedTags[$tag] = TRUE;
        }
        $paths[$path][strtolower($verb)] = $item;
      }
    }

    $settings = $this->configFactory->get('openapi_explorer.settings');
    $title = trim((string) ($settings->get('info.title') ?? ''));
    if ($title === '') {
      $title = trim((string) $this->configFactory->get('system.site')->get('name'));
      $title = ($title !== '' ? $title : 'Site') . ' API';
    }
    $version = trim((string) ($settings->get('info.version') ?? ''));
    $description = trim((string) ($settings->get('info.description') ?? ''));

    $info = [
      'title' => $title,
      'version' => $version !== '' ? $version : '1.0.0',
    ];
    if ($description !== '') {
      $info['description'] = $description;
    }

    $spec = [
      'openapi' => '3.0.3',
      'info' => $info,
    ];
    $servers = $this->servers();
    if ($servers) {
      $spec['servers'] = $servers;
    }
    if ($usedTags) {
      $spec['tags'] = array_map(function ($name) {
        return ['name' => $name];
      }, array_keys($usedTags));
    }
    $spec['paths'] = $paths ?: new \stdClass();
    $spec['components'] = [
      'schemas' => $this->components->toOpenApiSchemas() ?: new \stdClass(),
      'securitySchemes' => $this->authSchemes->schemes($usedSecurity) ?: new \stdClass(),
    ];

    return $this->spec = $spec;
  }

  /**
   * The configured server entries for the spec.
   *
   * @return array[]
   *   OpenAPI server objects.
   */
  protected function servers(): array {
    $configured = (array) ($this->configFactory->get('openapi_explorer.settings')->get('servers') ?? []);
    $servers = [];
    foreach ($configured as $url) {
      $url = trim((string) $url);
      if ($url !== '') {
        $servers[] = ['url' => $url];
      }
    }
    return $servers;
  }

  /**
   * The spec encoded as pretty JSON.
   *
   * @return string
   *   The JSON document.
   */
  public function toJson(): string {
    $json = json_encode($this->spec(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return $json === FALSE ? '{}' : $json;
  }

  /**
   * The spec encoded as YAML.
   *
   * @return string
   *   The YAML document.
   */
  public function toYaml(): string {
    return Yaml::dump($this->spec(), 10, 2, Yaml::DUMP_OBJECT_AS_MAP);
  }

  /**
   * Builds all endpoint fragments once.
   */
  protected function ensureBuilt(): void {
    if ($this->endpoints !== NULL) {
      return;
    }
    $this->endpoints = [];
    foreach ($this->discovery->inboundRows() as $row) {
      $id = (string) ($row['id'] ?? '');
      if ($id === '') {
        continue;
      }
      $endpoint = $this->buildEndpoint($row);
      if ($endpoint === NULL) {
        // Every operation was marked internal.
        continue;
      }
      $this->endpoints[$id] = $endpoint;
    }
    $this->inheritCategoryBySiblingPath();
    // Let other modules overlay curated documentation onto the discovered set.
    $this->moduleHandler->alter('openapi_explorer_endpoints', $this->endpoints);
  }

  /**
   * Shares category/description across endpoints that expose the same path.
   *
   * A resource that annotates only some verbs leaves its other verbs without a
   * category or description. Since they describe the same path, the missing
   * values are backfilled from an annotated same-path sibling so every verb
   * groups under the same category.
   */
  protected function inheritCategoryBySiblingPath(): void {
    $byPath = [];
    foreach ($this->endpoints as $endpoint) {
      $path = (string) ($endpoint['path'] ?? '');
      if ($path === '') {
        continue;
      }
      if (!isset($byPath[$path])) {
        $byPath[$path] = ['category' => '', 'description' => ''];
      }
      if ($byPath[$path]['category'] === '' && !empty($endpoint['category'])) {
        $byPath[$path]['category'] = $endpoint['category'];
      }
      if ($byPath[$path]['description'] === '' && !empty($endpoint['description'])) {
        $byPath[$path]['description'] = $endpoint['description'];
      }
    }
    foreach ($this->endpoints as $id => $endpoint) {
      $path = (string) ($endpoint['path'] ?? '');
      if ($path === '' || !isset($byPath[$path])) {
        continue;
      }
      if (($endpoint['category'] ?? '') === '' && $byPath[$path]['category'] !== '') {
        $this->endpoints[$id]['category'] = $byPath[$path]['category'];
      }
      if (($endpoint['description'] ?? '') === '' && $byPath[$path]['description'] !== '') {
        $this->endpoints[$id]['description'] = $byPath[$path]['description'];
      }
    }
  }

  /**
   * Builds a single endpoint fragment from a discovered row.
   *
   * @param array $row
   *   The discovered row.
   *
   * @return array|null
   *   The fragment, or NULL when the whole endpoint is marked internal.
   */
  protected function buildEndpoint(array $row): ?array {
    $resolved = $this->reflector->resolve($row);
    $verbs = $this->rowVerbs($row);
    $operations = [];
    $annotated = 0;
    foreach ($verbs as $verb) {
      $operation = $this->buildOperation($row, $resolved, $verb);
      if (!empty($operation['internal'])) {
        continue;
      }
      if ($operation['coverage'] === 'annotated') {
        $annotated++;
      }
      $operations[$verb] = $operation;
    }
    if (!$operations) {
      return NULL;
    }

    $coverage = 'none';
    if (($resolved['type'] ?? 'none') !== 'none' || $annotated > 0) {
      $coverage = 'partial';
    }
    if ($annotated === count($operations)) {
      $coverage = 'annotated';
    }

    // Aggregate endpoint-level category/description from the first operation
    // that declares them.
    $category = '';
    $description = '';
    $deprecated = TRUE;
    foreach ($operations as $operation) {
      if ($category === '' && !empty($operation['category'])) {
        $category = $operation['category'];
      }
      if ($description === '' && !empty($operation['description'])) {
        $description = $operation['description'];
      }
      $deprecated = $deprecated && !empty($operation['deprecated']);
    }

    return [
      'id' => (string) ($row['id'] ?? ''),
      'path' => (string) ($row['path'] ?? ''),
      'module' => (string) ($row['module'] ?? ''),
      'source' => (string) ($row['source'] ?? ''),
      'group' => (string) ($row['group'] ?? ''),
      'category' => $category,
      'description' => $description,
      'deprecated' => $deprecated,
      'auth' => $this->rowAuth($row),
      'class' => (string) ($resolved['class'] ?? ''),
      'class_method' => (string) ($resolved['method'] ?? ''),
      'resolved' => ($resolved['type'] ?? 'none') !== 'none',
      'coverage' => $coverage,
      'operations' => $operations,
    ];
  }

  /**
   * Builds a single operation (verb) fragment.
   *
   * @param array $row
   *   The discovered row.
   * @param array $resolved
   *   The reflector's resolution of the row.
   * @param string $verb
   *   The HTTP verb.
   *
   * @return array
   *   The operation fragment.
   */
  protected function buildOperation(array $row, array $resolved, string $verb): array {
    $method = $this->reflector->methodFor($resolved, $verb);
    $annotations = $method
      ? $this->parser->parseMethod($method)
      : $this->parser->emptyModel();
    $annotations = $this->parser->merge($this->classAnnotations($resolved), $annotations);

    $parameters = $this->mergeParameters($resolved, $method, $annotations);
    $request = $this->buildRequest($verb, $annotations);
    $responses = $this->buildResponses($row, $resolved, $verb, $annotations);
    $warnings = array_merge(
      $annotations['warnings'],
      $this->validatePathParams($resolved, $annotations)
    );

    $summary = $annotations['summary'] !== ''
      ? $annotations['summary']
      : $this->fallbackSummary($row, $verb);

    return [
      'method' => $verb,
      'summary' => $summary,
      'description' => $annotations['description'],
      'category' => $annotations['category'],
      'tags' => $annotations['tags'],
      'operation_id' => $annotations['operation_id'],
      'security' => $annotations['security'],
      'deprecated' => (bool) $annotations['deprecated'],
      'deprecated_reason' => $annotations['deprecated_reason'],
      'internal' => (bool) $annotations['internal'],
      'coverage' => $annotations['has_annotations'] ? 'annotated' : 'partial',
      'has_request' => in_array($verb, self::WRITE_VERBS, TRUE),
      'parameters' => $parameters,
      'parameters_by_in' => $this->groupParameters($parameters),
      'request' => $request,
      'responses' => $responses,
      'warnings' => $warnings,
    ];
  }

  /**
   * Returns the class-level annotation model for a resolved row.
   *
   * @param array $resolved
   *   The reflector's resolution of the row.
   *
   * @return array
   *   The annotation model.
   */
  protected function classAnnotations(array $resolved): array {
    $class = (string) ($resolved['class'] ?? '');
    if ($class === '') {
      return $this->parser->emptyModel();
    }
    if (isset($this->classModels[$class])) {
      return $this->classModels[$class];
    }
    $reflection = $this->reflector->classFor($resolved);
    $model = $reflection ? $this->parser->parseClass($reflection) : $this->parser->emptyModel();
    return $this->classModels[$class] = $model;
  }

  /**
   * Warns when annotated path parameters do not match the route's tokens.
   *
   * @param array $resolved
   *   The reflector's resolution of the row.
   * @param array $annotations
   *   The annotation model.
   *
   * @return string[]
   *   Warning messages.
   */
  protected function validatePathParams(array $resolved, array $annotations): array {
    $actual = array_flip($resolved['path_params'] ?? []);
    $annotatedNames = [];
    foreach ($annotations['params'] as $param) {
      if (($param['in'] ?? '') === 'path') {
        $annotatedNames[$param['name']] = TRUE;
      }
    }
    $warnings = [];
    foreach (array_keys($annotatedNames) as $name) {
      if (!isset($actual[$name])) {
        $warnings[] = $this->parser->tagName('Param') . ' path ' . $name . ': the route path has no {' . $name . '} token.';
      }
    }
    if ($annotations['has_annotations']) {
      foreach (array_keys($actual) as $name) {
        if (!isset($annotatedNames[$name])) {
          $warnings[] = 'The {' . $name . '} path parameter is not documented with ' . $this->parser->tagName('Param') . '.';
        }
      }
    }
    return $warnings;
  }

  /**
   * Merges auto-derived (path plus scanned query) parameters with annotated.
   *
   * Annotated parameters override auto entries of the same in|name and add new
   * ones; auto entries carry an 'auto' flag so the UI can mark them.
   *
   * @param array $resolved
   *   The reflector's resolution of the row.
   * @param \ReflectionMethod|null $method
   *   The backing method, when resolved.
   * @param array $annotations
   *   The annotation model.
   *
   * @return array[]
   *   The merged parameter descriptors.
   */
  protected function mergeParameters(array $resolved, ?\ReflectionMethod $method, array $annotations): array {
    $params = [];
    $index = [];
    $put = function (array $param, bool $auto) use (&$params, &$index) {
      $param += [
        'in' => 'query',
        'name' => '',
        'type' => 'string',
        'required' => FALSE,
        'description' => '',
      ];
      if ($param['name'] === '') {
        return;
      }
      $param['auto'] = $auto;
      $key = $param['in'] . '|' . $param['name'];
      if (isset($index[$key])) {
        if (!$auto) {
          $params[$index[$key]] = $param;
        }
        return;
      }
      $index[$key] = count($params);
      $params[] = $param;
    };

    foreach ($resolved['path_params'] ?? [] as $name) {
      $put(['in' => 'path', 'name' => $name, 'type' => 'string', 'required' => TRUE], TRUE);
    }
    foreach ($this->reflector->queryKeys($method) as $name) {
      $put(['in' => 'query', 'name' => $name], TRUE);
    }
    foreach ($annotations['params'] as $param) {
      $put($param, FALSE);
    }
    return $params;
  }

  /**
   * Groups parameters by location, so templates need no filtering logic.
   *
   * @param array $parameters
   *   The merged parameter descriptors.
   *
   * @return array
   *   Map of 'path', 'query' and 'header' to their parameters.
   */
  protected function groupParameters(array $parameters): array {
    $grouped = ['path' => [], 'query' => [], 'header' => []];
    foreach ($parameters as $parameter) {
      $in = (string) ($parameter['in'] ?? 'query');
      if (!isset($grouped[$in])) {
        $grouped[$in] = [];
      }
      $grouped[$in][] = $parameter;
    }
    return $grouped;
  }

  /**
   * Builds the request-body descriptor for write verbs.
   *
   * @param string $verb
   *   The HTTP verb.
   * @param array $annotations
   *   The annotation model.
   *
   * @return array
   *   The request descriptor.
   */
  protected function buildRequest(string $verb, array $annotations): array {
    if (!in_array($verb, self::WRITE_VERBS, TRUE)) {
      return ['applicable' => FALSE, 'documented' => FALSE, 'fields' => [], 'sample' => ''];
    }
    $fields = $annotations['request'];
    $sample = $annotations['request_example'];
    if ($sample === '') {
      $sample = $this->encodeSample($this->components->sampleForFields($fields));
    }
    return [
      'applicable' => TRUE,
      'documented' => !empty($fields) || $annotations['request_example'] !== '',
      'fields' => $fields,
      'sample' => $sample,
    ];
  }

  /**
   * Encodes a sample payload as pretty JSON.
   *
   * @param mixed $sample
   *   The sample payload.
   *
   * @return string
   *   Pretty JSON, or "{}" when it cannot be encoded.
   */
  protected function encodeSample($sample): string {
    if ($sample === [] || $sample === NULL) {
      $sample = new \stdClass();
    }
    $json = json_encode($sample, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return $json === FALSE ? '{}' : $json;
  }

  /**
   * Builds the response descriptors, scaffolding a 200 when none are annotated.
   *
   * @param array $row
   *   The discovered row.
   * @param array $resolved
   *   The reflector's resolution of the row.
   * @param string $verb
   *   The HTTP verb.
   * @param array $annotations
   *   The annotation model.
   *
   * @return array
   *   Response descriptors keyed by status code.
   */
  protected function buildResponses(array $row, array $resolved, string $verb, array $annotations): array {
    $responses = [];
    foreach ($annotations['responses'] as $status => $response) {
      $responses[(string) $status] = [
        'description' => $response['description'] ?? '',
        'component' => $response['component'] ?? NULL,
        'fields' => $response['fields'] ?? [],
        'example' => $response['example'] ?? '',
        'auto' => FALSE,
      ];
    }
    if (!$responses) {
      $responses['200'] = [
        'description' => 'Success (auto-detected; not yet annotated).',
        'component' => $this->guessEnvelope($row, $resolved, $verb),
        'fields' => [],
        'example' => '',
        'auto' => TRUE,
      ];
    }
    ksort($responses);
    return $responses;
  }

  /**
   * Lets other modules name a fallback response component for an operation.
   *
   * Nothing is guessed by default: an incorrect schema is more misleading than
   * an unspecified one. Sites whose responses follow a house convention can
   * implement hook_openapi_explorer_envelope_alter() to supply one.
   *
   * @param array $row
   *   The discovered row.
   * @param array $resolved
   *   The reflector's resolution of the row.
   * @param string $verb
   *   The HTTP verb.
   *
   * @return string|null
   *   A component name, or NULL.
   */
  protected function guessEnvelope(array $row, array $resolved, string $verb): ?string {
    $component = NULL;
    $context = ['row' => $row, 'resolved' => $resolved, 'verb' => $verb];
    $this->moduleHandler->alter('openapi_explorer_envelope', $component, $context);
    return ($component !== NULL && $this->components->has((string) $component)) ? (string) $component : NULL;
  }

  /**
   * The verbs to document for a row.
   *
   * @param array $row
   *   The discovered row.
   *
   * @return string[]
   *   The verbs.
   */
  protected function rowVerbs(array $row): array {
    $declared = array_map('strtoupper', (array) ($row['methods'] ?? ['GET']));
    $verbs = array_values(array_intersect(self::VERBS, $declared));
    return $verbs ?: ['GET'];
  }

  /**
   * Normalizes a row's auth to a list of provider strings.
   *
   * @param array $row
   *   The discovered row.
   *
   * @return string[]
   *   The auth provider ids.
   */
  protected function rowAuth(array $row): array {
    $auth = $row['auth'] ?? [];
    if (is_array($auth)) {
      return array_values(array_map('strval', $auth));
    }
    return $auth !== '' ? [(string) $auth] : [];
  }

  /**
   * A readable fallback summary when none is annotated.
   *
   * @param array $row
   *   The discovered row.
   * @param string $verb
   *   The HTTP verb.
   *
   * @return string
   *   The summary.
   */
  protected function fallbackSummary(array $row, string $verb): string {
    $id = (string) ($row['id'] ?? '');
    return trim($verb . ' ' . (string) ($row['path'] ?? $id));
  }

  /**
   * Builds one OpenAPI operation object from an endpoint plus operation.
   *
   * @param array $endpoint
   *   The endpoint fragment.
   * @param string $verb
   *   The HTTP verb.
   * @param array $operation
   *   The operation fragment.
   *
   * @return array
   *   The OpenAPI operation object.
   */
  protected function specOperation(array $endpoint, string $verb, array $operation): array {
    $tags = $operation['tags'] ?: [$endpoint['module'] !== '' ? $endpoint['module'] : 'api'];
    $item = [
      'summary' => $operation['summary'],
      'operationId' => $operation['operation_id'] !== ''
        ? $operation['operation_id']
        : $this->operationId($endpoint['id'], $verb),
      'tags' => array_values($tags),
    ];
    $description = (string) $operation['description'];
    if ($description === '' && ($operation['coverage'] ?? '') !== 'annotated') {
      $description = 'Auto-scaffolded operation; request and response schemas may be incomplete.';
    }
    if ($description !== '') {
      $item['description'] = $description;
    }
    if (!empty($operation['deprecated'])) {
      $item['deprecated'] = TRUE;
    }

    $parameters = [];
    foreach ($operation['parameters'] as $param) {
      $parameter = [
        'name' => $param['name'],
        'in' => $param['in'],
        'required' => (bool) $param['required'],
        'schema' => $this->components->typeSchema((string) ($param['type'] ?? 'string')),
      ];
      if (($param['description'] ?? '') !== '') {
        $parameter['description'] = $param['description'];
      }
      $parameters[] = $parameter;
    }
    if ($parameters) {
      $item['parameters'] = $parameters;
    }

    if (!empty($operation['request']['applicable']) && !empty($operation['request']['documented'])) {
      $content = ['schema' => $this->components->fieldsToSchema($operation['request']['fields'])];
      $example = $this->decodeExample((string) ($operation['request']['sample'] ?? ''));
      if ($example !== NULL) {
        $content['example'] = $example;
      }
      $item['requestBody'] = [
        'required' => TRUE,
        'content' => ['application/json' => $content],
      ];
    }

    $responses = [];
    foreach ($operation['responses'] as $status => $response) {
      $content = ['schema' => $this->responseSchema($response)];
      $example = $this->decodeExample((string) ($response['example'] ?? ''));
      if ($example !== NULL) {
        $content['example'] = $example;
      }
      $responses[(string) $status] = [
        'description' => $response['description'] !== '' ? $response['description'] : 'Response',
        'content' => ['application/json' => $content],
      ];
    }
    $item['responses'] = $responses ?: ['200' => ['description' => 'Response']];

    $security = $this->operationSecurity($endpoint['auth'], $operation['security']);
    if ($security !== NULL) {
      $item['security'] = $security;
    }
    return $item;
  }

  /**
   * Decodes a stored JSON example back into a value for the spec.
   *
   * @param string $json
   *   The JSON text.
   *
   * @return mixed|null
   *   The decoded value, or NULL when there is no usable example.
   */
  protected function decodeExample(string $json) {
    if (trim($json) === '') {
      return NULL;
    }
    $decoded = json_decode($json, TRUE);
    if ($decoded === NULL || $decoded === []) {
      return NULL;
    }
    return $decoded;
  }

  /**
   * Builds the schema for a single response (component ref and/or fields).
   *
   * @param array $response
   *   The response descriptor.
   *
   * @return array
   *   The JSON-Schema object.
   */
  protected function responseSchema(array $response): array {
    $component = $response['component'] ?? NULL;
    $hasFields = !empty($response['fields']);
    if ($component !== NULL && $this->components->has((string) $component)) {
      $ref = ['$ref' => '#/components/schemas/' . $component];
      if (!$hasFields) {
        return $ref;
      }
      return ['allOf' => [$ref, $this->components->fieldsToSchema($response['fields'])]];
    }
    if ($hasFields) {
      return $this->components->fieldsToSchema($response['fields']);
    }
    return ['type' => 'object'];
  }

  /**
   * Maps auth providers to OpenAPI security requirement objects.
   *
   * @param string[] $auth
   *   The route's auth providers.
   * @param string[] $annotated
   *   Scheme names declared by annotation; "none" forces a public operation.
   *
   * @return array|null
   *   The security requirements, or NULL when the operation should not carry a
   *   security key at all.
   */
  protected function operationSecurity(array $auth, array $annotated): ?array {
    if ($annotated) {
      if (count($annotated) === 1 && strtolower($annotated[0]) === 'none') {
        // An explicitly public operation: an empty requirement list.
        return [];
      }
      $security = [];
      foreach ($annotated as $scheme) {
        $security[] = [$scheme => []];
      }
      return $security;
    }
    $security = [];
    foreach ($auth as $provider) {
      $name = $this->authSchemes->schemeName((string) $provider);
      if ($name !== '') {
        $security[] = [$name => []];
      }
    }
    return $security ?: NULL;
  }

  /**
   * Builds a stable operationId from an endpoint id and verb.
   *
   * @param string $id
   *   The endpoint id.
   * @param string $verb
   *   The HTTP verb.
   *
   * @return string
   *   The operation id.
   */
  protected function operationId(string $id, string $verb): string {
    return strtolower($verb) . '_' . preg_replace('/[^A-Za-z0-9]+/', '_', $id);
  }

  /**
   * The configured path prefixes, normalized.
   *
   * @return string[]
   *   The prefixes.
   */
  protected function pathPrefixes(): array {
    $configured = (array) ($this->configFactory->get('openapi_explorer.settings')->get('scan.path_prefixes') ?? []);
    $prefixes = [];
    foreach ($configured as $prefix) {
      $prefix = trim((string) $prefix);
      if ($prefix !== '') {
        $prefixes[] = '/' . trim($prefix, '/');
      }
    }
    return $prefixes;
  }

  /**
   * Strips the longest configured path prefix from a path.
   *
   * @param string $path
   *   The endpoint path.
   *
   * @return string
   *   The remaining path.
   */
  protected function trimPrefix(string $path): string {
    $best = '';
    foreach ($this->pathPrefixes() as $prefix) {
      $matches = ($path === $prefix || strpos($path, $prefix . '/') === 0);
      if ($matches && strlen($prefix) > strlen($best)) {
        $best = $prefix;
      }
    }
    if ($best !== '') {
      $rest = substr($path, strlen($best));
      return $rest === '' ? '/' : $rest;
    }
    // No configured prefix matched; "/api" is the conventional namespace.
    if ($path === '/api' || strpos($path, '/api/') === 0) {
      $rest = substr($path, 4);
      return $rest === '' ? '/' : $rest;
    }
    return $path;
  }

  /**
   * Splits a path into its non-empty segments.
   *
   * @param string $path
   *   The path.
   *
   * @return string[]
   *   The segments.
   */
  protected function segments(string $path): array {
    return array_values(array_filter(explode('/', $path), function ($segment) {
      return $segment !== '';
    }));
  }

  /**
   * Derives [category, version] for a path.
   *
   * @param string $path
   *   The endpoint path.
   *
   * @return array
   *   [string $category, string $version]; the version is '' when absent.
   */
  protected function categoryForPath(string $path): array {
    $segments = $this->segments($this->trimPrefix($path));
    $first = $segments[0] ?? '';
    if ($first !== '' && $this->isVersion($first)) {
      $next = $segments[1] ?? '';
      return [$next !== '' ? $next : '(root)', $first];
    }
    return [$first !== '' ? $first : '(root)', ''];
  }

  /**
   * Whether a path segment is a version marker (v1, v2, v10, ...).
   *
   * @param string $segment
   *   The path segment.
   *
   * @return bool
   *   TRUE when the segment looks like a version.
   */
  protected function isVersion(string $segment): bool {
    return (bool) preg_match('/^v\d+$/i', $segment);
  }

  /**
   * The sidebar display path: the tail after the prefix and version segments.
   *
   * @param string $path
   *   The full endpoint path.
   *
   * @return string
   *   The remaining path.
   */
  protected function displayPath(string $path): string {
    $segments = $this->segments($this->trimPrefix($path));
    if ($segments && $this->isVersion($segments[0])) {
      array_shift($segments);
    }
    return '/' . implode('/', $segments);
  }

  /**
   * Slugifies a value for use in an HTML id/anchor.
   *
   * @param string $value
   *   The value to slugify.
   *
   * @return string
   *   The slug.
   */
  protected function slug(string $value): string {
    $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($value));
    $slug = trim((string) $slug, '-');
    return $slug !== '' ? $slug : 'x';
  }

}
