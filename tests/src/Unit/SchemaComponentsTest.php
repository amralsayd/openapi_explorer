<?php

namespace Drupal\Tests\openapi_explorer\Unit;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\openapi_explorer\OpenApi\SchemaComponents;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the schema component catalog and the type expression grammar.
 *
 * @coversDefaultClass \Drupal\openapi_explorer\OpenApi\SchemaComponents
 * @group openapi_explorer
 */
class SchemaComponentsTest extends UnitTestCase {

  /**
   * The component catalog under test.
   *
   * @var \Drupal\openapi_explorer\OpenApi\SchemaComponents
   */
  protected $components;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('alter')->willReturnCallback(function () {});
    $this->components = new SchemaComponents($moduleHandler);
  }

  /**
   * Tests that the default catalog is present and generic.
   *
   * @covers ::all
   * @covers ::has
   * @covers ::names
   */
  public function testDefaultCatalog() {
    $names = $this->components->names();
    $this->assertContains('ErrorResponse', $names);
    $this->assertContains('PaginatedResponse', $names);
    $this->assertContains('PaginationMeta', $names);
    $this->assertTrue($this->components->has('ErrorResponse'));
    $this->assertFalse($this->components->has('NoSuchComponent'));
    $this->assertNull($this->components->get('NoSuchComponent'));
  }

  /**
   * Tests that the alter hook can add components.
   *
   * @covers ::all
   */
  public function testAlterAddsComponents() {
    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('alter')
      ->willReturnCallback(function ($type, &$data) {
        $data['MyEnvelope'] = ['type' => 'object', 'fields' => []];
      });
    $components = new SchemaComponents($moduleHandler);
    $this->assertTrue($components->has('MyEnvelope'));
  }

  /**
   * Tests the primitive type normalisation.
   *
   * @covers ::normalizeType
   * @dataProvider providerNormalizeType
   */
  public function testNormalizeType($input, $expected) {
    $this->assertSame($expected, SchemaComponents::normalizeType($input));
  }

  /**
   * Data provider for testNormalizeType().
   *
   * @return array
   *   Test cases of [input, expected].
   */
  public function providerNormalizeType() {
    return [
      ['int', 'integer'],
      ['Integer', 'integer'],
      ['float', 'number'],
      ['bool', 'boolean'],
      ['list', 'array'],
      ['map', 'object'],
      ['', 'string'],
      ['Whatever', 'string'],
    ];
  }

  /**
   * Tests the extended type expression grammar.
   *
   * @covers ::typeSchema
   */
  public function testTypeSchema() {
    $this->assertSame(
      ['type' => 'string', 'enum' => ['draft', 'published']],
      $this->components->typeSchema('string(draft|published)')
    );
    $this->assertSame(
      ['type' => 'integer', 'enum' => [1, 2]],
      $this->components->typeSchema('integer(1|2)')
    );
    $this->assertSame(
      ['type' => 'array', 'items' => ['type' => 'integer']],
      $this->components->typeSchema('array<integer>')
    );
    $this->assertSame(
      ['type' => 'string', 'format' => 'date-time'],
      $this->components->typeSchema('datetime')
    );
    $this->assertSame(
      ['type' => 'string', 'format' => 'email'],
      $this->components->typeSchema('email')
    );
    $this->assertSame(
      ['$ref' => '#/components/schemas/ErrorResponse'],
      $this->components->typeSchema('ErrorResponse')
    );
    $this->assertSame(
      ['$ref' => '#/components/schemas/ErrorResponse'],
      $this->components->typeSchema('$ErrorResponse')
    );
    $this->assertSame(
      ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/ErrorResponse']],
      $this->components->typeSchema('array<ErrorResponse>')
    );
  }

  /**
   * Tests that a description on a reference is carried through allOf.
   *
   * @covers ::fieldToSchema
   */
  public function testReferenceKeepsDescription() {
    $schema = $this->components->fieldToSchema([
      'name' => 'meta',
      'type' => 'PaginationMeta',
      'description' => 'The metadata.',
    ]);
    $this->assertSame('The metadata.', $schema['description']);
    $this->assertSame('#/components/schemas/PaginationMeta', $schema['allOf'][0]['$ref']);
  }

  /**
   * Tests that dotted and bracketed field names build a nested schema.
   *
   * @covers ::fieldsToSchema
   */
  public function testNestedFieldsToSchema() {
    $schema = $this->components->fieldsToSchema([
      ['name' => 'data.items[].id', 'type' => 'integer', 'required' => TRUE],
      ['name' => 'data.items[].label', 'type' => 'string'],
      ['name' => 'total', 'type' => 'integer'],
    ]);

    $this->assertSame('object', $schema['type']);
    $this->assertArrayHasKey('data', $schema['properties']);
    $this->assertArrayHasKey('total', $schema['properties']);
    // "data" is an intermediate level, so it is an object, not a leaf.
    $this->assertSame('object', $schema['properties']['data']['type']);

    $items = $schema['properties']['data']['properties']['items'];
    $this->assertSame('array', $items['type']);
    $this->assertSame('object', $items['items']['type']);
    $this->assertSame('integer', $items['items']['properties']['id']['type']);
    $this->assertSame('string', $items['items']['properties']['label']['type']);
    $this->assertSame(['id'], $items['items']['required']);
    $this->assertSame('integer', $schema['properties']['total']['type']);
  }

  /**
   * Tests that an empty field list still produces a valid object schema.
   *
   * @covers ::fieldsToSchema
   */
  public function testEmptyFieldsToSchema() {
    $schema = $this->components->fieldsToSchema([]);
    $this->assertSame('object', $schema['type']);
    $this->assertInstanceOf(\stdClass::class, $schema['properties']);
  }

  /**
   * Tests sample generation, including through a component reference.
   *
   * @covers ::sampleForFields
   * @covers ::sampleForSchema
   */
  public function testSampleForFields() {
    $sample = $this->components->sampleForFields([
      ['name' => 'data.items[].id', 'type' => 'integer'],
      ['name' => 'when', 'type' => 'datetime'],
      ['name' => 'status', 'type' => 'string(a|b)'],
      ['name' => 'meta', 'type' => 'PaginationMeta'],
    ]);

    $this->assertSame(0, $sample['data']['items'][0]['id']);
    $this->assertSame('1970-01-01T00:00:00+00:00', $sample['when']);
    $this->assertSame('a', $sample['status']);
    $this->assertSame(0, $sample['meta']['total']);
  }

  /**
   * Tests the OpenAPI components/schemas projection.
   *
   * @covers ::toOpenApiSchemas
   */
  public function testToOpenApiSchemas() {
    $schemas = $this->components->toOpenApiSchemas();
    $this->assertArrayHasKey('ErrorResponse', $schemas);
    $this->assertSame('object', $schemas['ErrorResponse']['type']);
    $this->assertArrayHasKey('message', $schemas['ErrorResponse']['properties']);
    // PaginatedResponse references PaginationMeta by name.
    $this->assertSame(
      '#/components/schemas/PaginationMeta',
      $schemas['PaginatedResponse']['properties']['meta']['allOf'][0]['$ref']
    );
  }

}
