<?php

namespace Drupal\Tests\openapi_explorer_example\Functional;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\Role;

/**
 * Tests the sample endpoints and how OpenAPI Explorer documents them.
 *
 * @group openapi_explorer
 */
class ExampleApiTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'openapi_explorer',
    'openapi_explorer_example',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * A term referenced by one of the created nodes.
   *
   * @var \Drupal\taxonomy\TermInterface
   */
  protected $term;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->drupalCreateContentType(['type' => 'article', 'name' => 'Article']);
    $this->drupalCreateContentType(['type' => 'page', 'name' => 'Basic page']);

    Vocabulary::create(['vid' => 'tags', 'name' => 'Tags'])->save();
    $this->term = Term::create(['vid' => 'tags', 'name' => 'Announcements']);
    $this->term->save();
    $this->createTagsField();

    $tagged = Node::create([
      'type' => 'article',
      'title' => 'Tagged article',
      'status' => 1,
      'field_tags' => [['target_id' => $this->term->id()]],
    ]);
    $tagged->save();
    Node::create(['type' => 'article', 'title' => 'Plain article', 'status' => 1])->save();
    Node::create(['type' => 'page', 'title' => 'A page', 'status' => 1])->save();
    Node::create(['type' => 'page', 'title' => 'Draft page', 'status' => 0])->save();
  }

  /**
   * Adds a field_tags taxonomy reference field to the article type.
   */
  protected function createTagsField(): void {
    FieldStorageConfig::create([
      'field_name' => 'field_tags',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'cardinality' => -1,
      'settings' => ['target_type' => 'taxonomy_term'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_tags',
      'entity_type' => 'node',
      'bundle' => 'article',
      'label' => 'Tags',
    ])->save();
  }

  /**
   * Decodes the JSON body of the current response.
   *
   * @return array
   *   The decoded payload.
   */
  protected function json(): array {
    $decoded = json_decode($this->getSession()->getPage()->getContent(), TRUE);
    $this->assertIsArray($decoded, 'The response body is JSON.');
    return $decoded;
  }

  /**
   * Tests the controller node listing and its filters.
   */
  public function testNodeListing() {
    $this->drupalLogin($this->drupalCreateUser(['access content']));

    $this->drupalGet('/api/example/nodes');
    $this->assertSession()->statusCodeEquals(200);
    $payload = $this->json();
    // Only the three published nodes.
    $this->assertSame(3, $payload['meta']['total']);
    $this->assertCount(3, $payload['data']);
    $this->assertSame(20, $payload['meta']['per_page']);

    // Every documented field of the summary is present.
    $item = $payload['data'][0];
    foreach (['id', 'uuid', 'type', 'title', 'langcode', 'created', 'changed', 'author', 'url', 'tags'] as $key) {
      $this->assertArrayHasKey($key, $item);
    }

    // Filter by content type.
    $this->drupalGet('/api/example/nodes', ['query' => ['type' => 'page']]);
    $payload = $this->json();
    $this->assertSame(1, $payload['meta']['total']);
    $this->assertSame('page', $payload['data'][0]['type']);

    // Several content types at once.
    $this->drupalGet('/api/example/nodes', ['query' => ['type' => 'article,page']]);
    $this->assertSame(3, $this->json()['meta']['total']);

    // Filter by tag, by id and by name.
    $this->drupalGet('/api/example/nodes', ['query' => ['tags' => $this->term->id()]]);
    $payload = $this->json();
    $this->assertSame(1, $payload['meta']['total']);
    $this->assertSame('Tagged article', $payload['data'][0]['title']);
    $this->assertSame('Announcements', $payload['data'][0]['tags'][0]['name']);

    $this->drupalGet('/api/example/nodes', ['query' => ['tags' => 'Announcements']]);
    $this->assertSame(1, $this->json()['meta']['total']);

    // A tag nothing matches yields an empty page, never the unfiltered list.
    $this->drupalGet('/api/example/nodes', ['query' => ['tags' => 'NoSuchTerm']]);
    $this->assertSame(0, $this->json()['meta']['total']);

    // Paging.
    $this->drupalGet('/api/example/nodes', ['query' => ['limit' => 2, 'page' => 1]]);
    $payload = $this->json();
    $this->assertSame(3, $payload['meta']['total']);
    $this->assertCount(1, $payload['data']);
    $this->assertSame(2, $payload['meta']['pages']);
  }

  /**
   * Tests the controller user listing and its role filter.
   */
  public function testUserListing() {
    Role::create(['id' => 'editor', 'label' => 'Editor'])->save();
    $editor = $this->drupalCreateUser(['access user profiles']);
    $editor->addRole('editor');
    $editor->save();

    $this->drupalLogin($editor);
    $this->drupalGet('/api/example/users');
    $this->assertSession()->statusCodeEquals(200);
    $payload = $this->json();
    $this->assertGreaterThan(0, $payload['meta']['total']);

    $item = $payload['data'][0];
    foreach (['id', 'uuid', 'name', 'display_name', 'status', 'created', 'roles'] as $key) {
      $this->assertArrayHasKey($key, $item);
    }
    // No personal data beyond the account name is exposed.
    $this->assertArrayNotHasKey('mail', $item);
    // The anonymous account is never listed.
    foreach ($payload['data'] as $account) {
      $this->assertGreaterThan(0, $account['id']);
    }

    $this->drupalGet('/api/example/users', ['query' => ['roles' => 'editor']]);
    $payload = $this->json();
    $this->assertSame(1, $payload['meta']['total']);
    $this->assertContains('editor', $payload['data'][0]['roles']);
  }

  /**
   * Tests that rejected filters answer with the documented JSON error shape.
   */
  public function testBadRequestsAreJson() {
    $this->drupalLogin($this->drupalCreateUser(['access content']));

    foreach ([
      ['type' => 'no_such_type'],
      ['limit' => 'abc'],
      ['page' => '-1'],
      ['tags' => '1', 'tags_field' => 'field_missing'],
    ] as $query) {
      $this->drupalGet('/api/example/nodes', ['query' => $query]);
      $this->assertSession()->statusCodeEquals(400);
      $this->assertSession()->responseHeaderContains('Content-Type', 'application/json');
      $payload = $this->json();
      $this->assertNotEmpty($payload['message']);
      $this->assertArrayHasKey('errors', $payload);
    }
  }

  /**
   * Tests that the endpoints are gated by their permissions.
   */
  public function testAccess() {
    $this->drupalGet('/api/example/users');
    $this->assertSession()->statusCodeEquals(403);

    // The REST resources have their own permissions.
    $this->drupalGet('/api/example/rest/nodes', ['query' => ['_format' => 'json']]);
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalLogin($this->drupalCreateUser([
      'access content',
      'restful get openapi_explorer_example_nodes',
    ]));
    $this->drupalGet('/api/example/rest/nodes', ['query' => ['_format' => 'json']]);
    $this->assertSession()->statusCodeEquals(200);
    $payload = $this->json();
    $this->assertSame(3, $payload['meta']['total']);

    // The REST resource accepts the same filters as the controller route.
    $this->drupalGet('/api/example/rest/nodes', [
      'query' => ['_format' => 'json', 'type' => 'page'],
    ]);
    $this->assertSame(1, $this->json()['meta']['total']);
  }

  /**
   * Tests that all four samples are documented, annotated and in the spec.
   */
  public function testSamplesAreDocumented() {
    $this->drupalLogin($this->drupalCreateUser(['access openapi explorer']));
    $this->drupalGet('/admin/config/services/openapi-explorer/openapi.json');
    $this->assertSession()->statusCodeEquals(200);
    $spec = $this->json();

    $paths = [
      '/api/example/nodes' => 'listExampleNodes',
      '/api/example/users' => 'listExampleUsers',
      '/api/example/rest/nodes' => 'restListExampleNodes',
      '/api/example/rest/users' => 'restListExampleUsers',
    ];
    foreach ($paths as $path => $operationId) {
      $this->assertArrayHasKey($path, $spec['paths'], "$path is documented");
      $operation = $spec['paths'][$path]['get'];
      $this->assertSame($operationId, $operation['operationId']);
      $this->assertNotEmpty($operation['summary']);
      $this->assertContains('example', $operation['tags']);
      // The 400 and 403 responses come from the class-level annotations on the
      // controller, and from the method docblocks on the REST resources.
      $this->assertArrayHasKey('400', $operation['responses']);
      $this->assertArrayHasKey('403', $operation['responses']);
    }

    // The components the example module registers through the alter hook.
    foreach (['NodeListResponse', 'UserListResponse', 'ExamplePaginationMeta'] as $component) {
      $this->assertArrayHasKey($component, $spec['components']['schemas']);
    }

    // The node listing references its component, and the nested field paths
    // became a real nested schema.
    $schema = $spec['paths']['/api/example/nodes']['get']['responses']['200']['content']['application/json']['schema'];
    $this->assertSame('#/components/schemas/NodeListResponse', $schema['$ref']);
    $nodeList = $spec['components']['schemas']['NodeListResponse'];
    $this->assertSame('array', $nodeList['properties']['data']['type']);
    $this->assertSame('integer', $nodeList['properties']['data']['items']['properties']['id']['type']);
    $this->assertSame('array', $nodeList['properties']['data']['items']['properties']['tags']['type']);

    // The response example survived into the specification.
    $this->assertArrayHasKey(
      'example',
      $spec['paths']['/api/example/nodes']['get']['responses']['200']['content']['application/json']
    );

    // And the documentation page lists them without any annotation warnings.
    $this->drupalGet('/admin/config/services/openapi-explorer');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('List published nodes.');
    $this->assertSession()->pageTextContains('Example (controller)');
    $this->assertSession()->pageTextContains('Example (REST)');
  }

}
