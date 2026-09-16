<?php

namespace Drupal\Tests\openapi_explorer\Unit;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\openapi_explorer\OpenApi\DocAnnotationParser;
use Drupal\openapi_explorer\OpenApi\SchemaComponents;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the docblock annotation grammar.
 *
 * @coversDefaultClass \Drupal\openapi_explorer\OpenApi\DocAnnotationParser
 * @group openapi_explorer
 */
class DocAnnotationParserTest extends UnitTestCase {

  /**
   * Builds a parser with the given tag prefix.
   *
   * @param string $prefix
   *   The docblock tag prefix.
   *
   * @return \Drupal\openapi_explorer\OpenApi\DocAnnotationParser
   *   The parser.
   */
  protected function parser(string $prefix = 'oapi'): DocAnnotationParser {
    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('alter')->willReturnCallback(function () {});
    $configFactory = $this->getConfigFactoryStub([
      'openapi_explorer.settings' => ['annotations.tag_prefix' => $prefix],
    ]);
    return new DocAnnotationParser($configFactory, new SchemaComponents($moduleHandler));
  }

  /**
   * Tests that a docblock with no tags yields the empty model.
   *
   * @covers ::parseDocComment
   * @covers ::emptyModel
   */
  public function testNoAnnotations() {
    $model = $this->parser()->parseDocComment("/**\n * Just a description.\n */");
    $this->assertFalse($model['has_annotations']);
    $this->assertSame('', $model['summary']);
    $this->assertSame([], $model['params']);
    $this->assertSame([], $model['responses']);
  }

  /**
   * Tests the descriptive tags.
   *
   * @covers ::parseDocComment
   */
  public function testDescriptiveTags() {
    $model = $this->parser()->parseDocComment('
      @oapiSummary List the items.
      @oapiDescription A longer explanation.
      @oapiCategory Content
      @oapiTag public
      @oapiTag beta
      @oapiOperationId listItems
    ');
    $this->assertTrue($model['has_annotations']);
    $this->assertSame('List the items.', $model['summary']);
    $this->assertSame('A longer explanation.', $model['description']);
    $this->assertSame('Content', $model['category']);
    $this->assertSame(['public', 'beta'], $model['tags']);
    $this->assertSame('listItems', $model['operation_id']);
  }

  /**
   * Tests the operation flag tags.
   *
   * @covers ::parseDocComment
   */
  public function testFlagTags() {
    $model = $this->parser()->parseDocComment('
      @oapiDeprecated Use v2 instead.
      @oapiInternal
      @oapiSecurity key_auth oauth2
    ');
    $this->assertTrue($model['deprecated']);
    $this->assertSame('Use v2 instead.', $model['deprecated_reason']);
    $this->assertTrue($model['internal']);
    $this->assertSame(['key_auth', 'oauth2'], $model['security']);
  }

  /**
   * Tests parameter parsing, including the implicit rules.
   *
   * @covers ::parseParam
   */
  public function testParams() {
    $model = $this->parser()->parseDocComment('
      @oapiParam query status string - Filter by status.
      @oapiParam path id integer required - The id.
      @oapiParam header X-Trace string
      @oapiParam query flag boolean required
      @oapiParam pageSize integer - No location given.
    ');

    $params = $model['params'];
    $this->assertCount(5, $params);

    $this->assertSame(['query', 'status', 'string'], [$params[0]['in'], $params[0]['name'], $params[0]['type']]);
    $this->assertFalse($params[0]['required']);
    $this->assertSame('Filter by status.', $params[0]['description']);

    // Path parameters are required even without the keyword.
    $this->assertSame('path', $params[1]['in']);
    $this->assertTrue($params[1]['required']);

    $this->assertSame('header', $params[2]['in']);
    $this->assertSame('', $params[2]['description']);

    $this->assertTrue($params[3]['required']);

    // A missing location falls back to query, with a warning.
    $this->assertSame('query', $params[4]['in']);
    $this->assertNotEmpty($model['warnings']);
  }

  /**
   * Tests request field parsing and the required flag.
   *
   * @covers ::parseField
   */
  public function testRequestFields() {
    $model = $this->parser()->parseDocComment('
      @oapiRequestField title string required - The title.
      @oapiRequestField data.items[].id integer - A nested id.
    ');
    $this->assertCount(2, $model['request']);
    $this->assertTrue($model['request'][0]['required']);
    $this->assertSame('data.items[].id', $model['request'][1]['name']);
    $this->assertFalse($model['request'][1]['required']);
  }

  /**
   * Tests that response fields attach to the most recent response.
   *
   * @covers ::parseResponse
   * @covers ::parseDocComment
   */
  public function testResponses() {
    $model = $this->parser()->parseDocComment('
      @oapiResponse 200 PaginatedResponse - A page of items.
      @oapiResponseField data array - The items.
      @oapiResponse 404 ErrorResponse - Not found.
      @oapiResponseField message string - Why it failed.
    ');

    // PHP casts numeric array keys to integers; the JSON encoding turns them
    // back into the string keys the specification requires.
    $this->assertSame(['200', '404'], array_map('strval', array_keys($model['responses'])));
    $this->assertSame('PaginatedResponse', $model['responses']['200']['component']);
    $this->assertSame('A page of items.', $model['responses']['200']['description']);
    $this->assertCount(1, $model['responses']['200']['fields']);
    $this->assertSame('data', $model['responses']['200']['fields'][0]['name']);
    $this->assertSame('message', $model['responses']['404']['fields'][0]['name']);
  }

  /**
   * Tests that a response field with no preceding response defaults to 200.
   *
   * @covers ::parseDocComment
   */
  public function testResponseFieldDefaultsTo200() {
    $model = $this->parser()->parseDocComment('@oapiResponseField id integer - The id.');
    $this->assertArrayHasKey('200', $model['responses']);
    $this->assertSame('id', $model['responses']['200']['fields'][0]['name']);
  }

  /**
   * Tests that a single unknown token is read as prose, not a component.
   *
   * @covers ::parseResponse
   */
  public function testResponseProseWithoutSeparator() {
    $model = $this->parser()->parseDocComment('@oapiResponse 204 Deleted');
    $this->assertNull($model['responses']['204']['component']);
    $this->assertSame('Deleted', $model['responses']['204']['description']);
  }

  /**
   * Tests inline JSON examples, valid and invalid.
   *
   * @covers ::parseExample
   */
  public function testExamples() {
    $model = $this->parser()->parseDocComment('
      @oapiRequestExample {"title":"Hello"}
      @oapiResponse 200 - OK.
      @oapiExample 200 {"id":1}
    ');
    $this->assertStringContainsString('"title": "Hello"', $model['request_example']);
    $this->assertStringContainsString('"id": 1', $model['responses']['200']['example']);
    $this->assertSame([], $model['warnings']);

    $bad = $this->parser()->parseDocComment('@oapiRequestExample {not json}');
    $this->assertSame('', $bad['request_example']);
    $this->assertNotEmpty($bad['warnings']);
  }

  /**
   * Tests the validation warnings.
   *
   * @covers ::parseDocComment
   * @covers ::validateComponents
   */
  public function testWarnings() {
    $model = $this->parser()->parseDocComment('
      @oapiResponse 99 - Not a status code.
      @oapiResponse 500 NoSuchComponent - Unknown component.
      @oapiNonsense something
      @oapiRequestField
    ');

    $warnings = implode("\n", $model['warnings']);
    $this->assertStringContainsString('three-digit status code', $warnings);
    $this->assertStringContainsString('NoSuchComponent', $warnings);
    $this->assertStringContainsString('Unknown annotation tag', $warnings);
    $this->assertStringContainsString('field name is required', $warnings);
    // The malformed status must not create a response entry.
    $this->assertArrayNotHasKey('99', $model['responses']);
  }

  /**
   * Tests that the tag prefix is configurable.
   *
   * @covers ::tagPrefix
   * @covers ::tagName
   * @covers ::parseDocComment
   */
  public function testConfigurableTagPrefix() {
    $parser = $this->parser('fxApi');
    $this->assertSame('fxApi', $parser->tagPrefix());
    $this->assertSame('@fxApiParam', $parser->tagName('Param'));

    $model = $parser->parseDocComment('
      @fxApiSummary A legacy annotated method.
      @fxApiParam query page integer - The page number.
    ');
    $this->assertTrue($model['has_annotations']);
    $this->assertSame('A legacy annotated method.', $model['summary']);
    $this->assertCount(1, $model['params']);

    // The default prefix is inert for this parser.
    $this->assertFalse($parser->parseDocComment('@oapiSummary Ignored.')['has_annotations']);
  }

  /**
   * Tests that an empty configured prefix falls back to the default.
   *
   * @covers ::tagPrefix
   */
  public function testEmptyPrefixFallsBack() {
    $this->assertSame(DocAnnotationParser::DEFAULT_TAG_PREFIX, $this->parser('')->tagPrefix());
    // A prefix written with the "@" is accepted too.
    $this->assertSame('oapi', $this->parser('@oapi')->tagPrefix());
  }

  /**
   * Tests merging class-level annotations into method-level ones.
   *
   * @covers ::merge
   */
  public function testMerge() {
    $parser = $this->parser();
    $class = $parser->parseDocComment('
      @oapiCategory Content
      @oapiTag shared
      @oapiSecurity key_auth
      @oapiResponse 403 ErrorResponse - Access denied.
    ');
    $method = $parser->parseDocComment('
      @oapiSummary List the items.
      @oapiTag listing
      @oapiResponse 200 - OK.
    ');

    $merged = $parser->merge($class, $method);
    // The method keeps its own summary and gains the class category.
    $this->assertSame('List the items.', $merged['summary']);
    $this->assertSame('Content', $merged['category']);
    $this->assertSame(['shared', 'listing'], $merged['tags']);
    $this->assertSame(['key_auth'], $merged['security']);
    $this->assertSame(['200', '403'], array_map('strval', array_keys($merged['responses'])));
  }

  /**
   * Tests that a method-level value wins over the class-level one.
   *
   * @covers ::merge
   */
  public function testMergeMethodWins() {
    $parser = $this->parser();
    $class = $parser->parseDocComment('@oapiCategory Shared');
    $method = $parser->parseDocComment('@oapiCategory Specific');
    $this->assertSame('Specific', $parser->merge($class, $method)['category']);
  }

  /**
   * Tests that an unannotated class leaves the method model untouched.
   *
   * @covers ::merge
   */
  public function testMergeWithEmptyClass() {
    $parser = $this->parser();
    $method = $parser->parseDocComment('@oapiSummary Only the method.');
    $this->assertSame($method, $parser->merge($parser->emptyModel(), $method));
  }

}
