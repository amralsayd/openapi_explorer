<?php

namespace Drupal\Tests\openapi_explorer\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests configuration-driven endpoint discovery and the built model.
 *
 * @group openapi_explorer
 */
class EndpointDiscoveryTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'serialization',
    'openapi_explorer',
    'openapi_explorer_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['openapi_explorer']);
  }

  /**
   * Sets discovery settings and returns a freshly built model.
   *
   * @param array $values
   *   Settings keys and values to apply.
   *
   * @return array
   *   Endpoint fragments keyed by path.
   */
  protected function endpointsWith(array $values): array {
    $config = $this->config('openapi_explorer.settings');
    foreach ($values as $key => $value) {
      $config->set($key, $value);
    }
    $config->save();

    // The services cache their results, so rebuild them for each scenario.
    $this->container->set('openapi_explorer.discovery', NULL);
    $this->container->set('openapi_explorer.builder', NULL);

    $endpoints = [];
    foreach ($this->container->get('openapi_explorer.builder')->endpoints() as $endpoint) {
      $endpoints[$endpoint['path']] = $endpoint;
    }
    return $endpoints;
  }

  /**
   * Tests that the default path prefix limits discovery to /api routes.
   */
  public function testPathPrefixFilter() {
    $endpoints = $this->endpointsWith([
      'scan.sources' => ['custom', 'contrib', 'profile', 'core'],
      'scan.path_prefixes' => ['/api'],
    ]);

    $this->assertArrayHasKey('/api/test/items', $endpoints);
    $this->assertArrayHasKey('/api/test/items/{item}', $endpoints);
    // Outside the prefix.
    $this->assertArrayNotHasKey('/other/test/bare', $endpoints);
  }

  /**
   * Tests that an empty prefix list documents every route.
   */
  public function testEmptyPrefixListMatchesEverything() {
    $endpoints = $this->endpointsWith([
      'scan.sources' => ['custom', 'contrib', 'profile', 'core'],
      'scan.path_prefixes' => [],
    ]);

    $this->assertArrayHasKey('/api/test/items', $endpoints);
    $this->assertArrayHasKey('/other/test/bare', $endpoints);
    // Core's own routes are now in scope as well.
    $this->assertGreaterThan(5, count($endpoints));
  }

  /**
   * Tests that administrative routes are excluded unless requested.
   */
  public function testAdminRoutesExcludedByDefault() {
    $endpoints = $this->endpointsWith([
      'scan.sources' => ['custom', 'contrib', 'profile', 'core'],
      'scan.path_prefixes' => ['/api'],
      'scan.include_admin_routes' => FALSE,
    ]);
    $this->assertArrayNotHasKey('/api/test/admin-only', $endpoints);

    $endpoints = $this->endpointsWith(['scan.include_admin_routes' => TRUE]);
    $this->assertArrayHasKey('/api/test/admin-only', $endpoints);
  }

  /**
   * Tests that the module allow list overrides the source selection.
   */
  public function testModuleAllowList() {
    $endpoints = $this->endpointsWith([
      'scan.sources' => ['custom', 'contrib', 'profile', 'core'],
      'scan.path_prefixes' => [],
      'scan.modules' => ['openapi_explorer_test'],
      'scan.include_admin_routes' => FALSE,
    ]);

    foreach ($endpoints as $endpoint) {
      $this->assertSame('openapi_explorer_test', $endpoint['module']);
    }
    $this->assertArrayHasKey('/api/test/items', $endpoints);
  }

  /**
   * Tests that excluded modules are skipped.
   */
  public function testExcludedModules() {
    $endpoints = $this->endpointsWith([
      'scan.sources' => ['custom', 'contrib', 'profile', 'core'],
      'scan.path_prefixes' => ['/api'],
      'scan.modules' => [],
      'scan.excluded_modules' => ['openapi_explorer_test'],
    ]);
    $this->assertArrayNotHasKey('/api/test/items', $endpoints);
  }

  /**
   * Tests that the endpoint cap truncates the result and reports it.
   */
  public function testMaxEndpointsCap() {
    $this->endpointsWith([
      'scan.sources' => ['custom', 'contrib', 'profile', 'core'],
      'scan.path_prefixes' => [],
      'scan.modules' => [],
      'scan.excluded_modules' => [],
      'scan.max_endpoints' => 3,
    ]);

    $coverage = $this->container->get('openapi_explorer.builder')->coverageSummary();
    $this->assertTrue($coverage['capped']);
    $this->assertLessThanOrEqual(3, $coverage['endpoints']['total']);
  }

  /**
   * Tests that an internal operation never reaches the documentation.
   */
  public function testInternalOperationIsHidden() {
    $endpoints = $this->endpointsWith([
      'scan.sources' => ['custom', 'contrib', 'profile', 'core'],
      'scan.path_prefixes' => ['/api'],
      'scan.modules' => [],
      'scan.excluded_modules' => [],
      'scan.max_endpoints' => 0,
    ]);

    $this->assertArrayHasKey('/api/test/items', $endpoints);
    $this->assertArrayNotHasKey('/api/test/internal', $endpoints);
  }

  /**
   * Tests that annotations, reflection and class-level tags are merged.
   */
  public function testAnnotationsAreApplied() {
    $endpoints = $this->endpointsWith([
      'scan.sources' => ['custom', 'contrib', 'profile', 'core'],
      'scan.path_prefixes' => ['/api'],
      'scan.modules' => [],
      'scan.excluded_modules' => [],
      'scan.max_endpoints' => 0,
    ]);

    $collection = $endpoints['/api/test/items'];
    $this->assertSame('annotated', $collection['coverage']);
    // Declared on the class docblock, inherited by every method.
    $this->assertSame('Testing', $collection['category']);

    $get = $collection['operations']['GET'];
    $this->assertSame('List the available items.', $get['summary']);
    $this->assertSame(['200', '403'], array_map('strval', array_keys($get['responses'])));
    $this->assertSame('PaginatedResponse', $get['responses']['200']['component']);

    // The "page" query key was found by scanning the method body, and the
    // annotated "status" parameter was merged in beside it.
    $names = [];
    foreach ($get['parameters'] as $parameter) {
      $names[$parameter['name']] = $parameter['auto'];
    }
    $this->assertArrayHasKey('page', $names);
    $this->assertTrue($names['page'], 'The scanned query key is flagged as automatic.');
    $this->assertArrayHasKey('status', $names);
    $this->assertFalse($names['status'], 'The annotated parameter is not automatic.');

    // POST carries a request body built from the nested request fields.
    $post = $collection['operations']['POST'];
    $this->assertTrue($post['request']['applicable']);
    $this->assertTrue($post['request']['documented']);
    $this->assertStringContainsString('label', $post['request']['sample']);

    // The single-item route is deprecated and explicitly public.
    $item = $endpoints['/api/test/items/{item}']['operations']['GET'];
    $this->assertTrue($item['deprecated']);
    $this->assertSame('getTestItem', $item['operation_id']);
    $this->assertSame(['none'], $item['security']);
    $this->assertSame(['item'], array_column($item['parameters_by_in']['path'], 'name'));
  }

  /**
   * Tests the generated specification.
   */
  public function testSpecification() {
    $this->endpointsWith([
      'scan.sources' => ['custom', 'contrib', 'profile', 'core'],
      'scan.path_prefixes' => ['/api'],
      'scan.modules' => [],
      'scan.excluded_modules' => [],
      'scan.max_endpoints' => 0,
      'info.title' => 'Fixture API',
      'info.version' => '2.1.0',
      'servers' => ['https://example.com'],
    ]);

    $builder = $this->container->get('openapi_explorer.builder');
    $spec = json_decode($builder->toJson(), TRUE);

    $this->assertSame('3.0.3', $spec['openapi']);
    $this->assertSame('Fixture API', $spec['info']['title']);
    $this->assertSame('2.1.0', $spec['info']['version']);
    $this->assertSame([['url' => 'https://example.com']], $spec['servers']);
    $this->assertArrayHasKey('/api/test/items', $spec['paths']);
    $this->assertArrayNotHasKey('/api/test/internal', $spec['paths']);

    $get = $spec['paths']['/api/test/items']['get'];
    $this->assertSame('List the available items.', $get['summary']);
    $this->assertContains('fixtures', $get['tags']);
    $this->assertSame(
      '#/components/schemas/PaginatedResponse',
      $get['responses']['200']['content']['application/json']['schema']['$ref']
    );

    // An enumerated query parameter becomes a JSON-Schema enum.
    $status = NULL;
    foreach ($get['parameters'] as $parameter) {
      if ($parameter['name'] === 'status') {
        $status = $parameter;
      }
    }
    $this->assertNotNull($status);
    $this->assertSame(['draft', 'published'], $status['schema']['enum']);

    // A deprecated operation is flagged, and "none" means no requirements.
    $item = $spec['paths']['/api/test/items/{item}']['get'];
    $this->assertTrue($item['deprecated']);
    $this->assertSame('getTestItem', $item['operationId']);
    $this->assertSame([], $item['security']);

    // The YAML rendering must agree with the JSON one.
    $yaml = Yaml::parse($builder->toYaml());
    $this->assertSame($spec['openapi'], $yaml['openapi']);
    $this->assertSame(array_keys($spec['paths']), array_keys($yaml['paths']));
  }

  /**
   * Tests that the component catalog can be extended by another module.
   */
  public function testSchemaComponentsAlter() {
    $components = $this->container->get('openapi_explorer.schema_components');
    $this->assertTrue($components->has('ErrorResponse'));
    $this->assertTrue($components->has('PaginatedResponse'));
    $this->assertTrue($components->has('PaginationMeta'));
  }

}
