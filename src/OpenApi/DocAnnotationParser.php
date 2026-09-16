<?php

namespace Drupal\openapi_explorer\OpenApi;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Parses the module's docblock tags on REST/controller methods into a model.
 *
 * The grammar is line-based and reads like @param - no JSON blobs, no PHP
 * attributes - so it works on every PHP version Drupal 9 to 11 supports:
 *
 *   @oapiSummary <free text>
 *   @oapiDescription <free text>
 *   @oapiCategory <name>
 *   @oapiTag <name>
 *   @oapiOperationId <id>
 *   @oapiSecurity <scheme> [<scheme>...]   ("none" marks a public operation)
 *   @oapiDeprecated [<reason>]
 *   @oapiInternal
 *   @oapiParam <in> <name> <type> [required] - <description>
 *   @oapiRequestField <name> <type> [required] - <description>
 *   @oapiRequestExample <inline JSON>
 *   @oapiResponse <status> [ComponentName] - <description>
 *   @oapiResponseField <name> <type> - <description>
 *   @oapiExample <status> <inline JSON>
 *
 * Where <in> is path, query or header; path parameters are always required.
 * @oapiResponseField and @oapiExample apply to the most recently declared
 * @oapiResponse status, defaulting to 200 when none was declared yet. A " - "
 * (space-dash-space) separates the machine tokens from the free-text
 * description, which is optional.
 *
 * Field names in @oapiRequestField and @oapiResponseField may describe nested
 * structures with dots and "[]" - see SchemaComponents::fieldsToSchema().
 *
 * The "@oapi" prefix is configurable, so a site that already annotated its code
 * with another prefix can adopt this module without editing any docblock.
 */
class DocAnnotationParser {

  /**
   * The tag prefix used when none is configured.
   */
  const DEFAULT_TAG_PREFIX = 'oapi';

  /**
   * Parameter locations this grammar accepts.
   */
  const PARAM_LOCATIONS = ['path', 'query', 'header'];

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The schema component catalog, for validating component references.
   *
   * @var \Drupal\openapi_explorer\OpenApi\SchemaComponents
   */
  protected $components;

  /**
   * The resolved tag prefix (lazy).
   *
   * @var string|null
   */
  protected $tagPrefix = NULL;

  /**
   * Constructs the annotation parser.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\openapi_explorer\OpenApi\SchemaComponents $components
   *   The schema component catalog.
   */
  public function __construct(ConfigFactoryInterface $config_factory, SchemaComponents $components) {
    $this->configFactory = $config_factory;
    $this->components = $components;
  }

  /**
   * Returns the configured docblock tag prefix, without the leading "@".
   *
   * @return string
   *   The tag prefix.
   */
  public function tagPrefix(): string {
    if ($this->tagPrefix !== NULL) {
      return $this->tagPrefix;
    }
    $configured = (string) $this->configFactory
      ->get('openapi_explorer.settings')
      ->get('annotations.tag_prefix');
    $configured = trim(ltrim($configured, '@'));
    return $this->tagPrefix = $configured !== '' ? $configured : self::DEFAULT_TAG_PREFIX;
  }

  /**
   * Returns a full tag name for display, e.g. "@oapiRequestField".
   *
   * @param string $suffix
   *   The tag suffix, e.g. "RequestField".
   *
   * @return string
   *   The full tag.
   */
  public function tagName(string $suffix): string {
    return '@' . $this->tagPrefix() . $suffix;
  }

  /**
   * Parses a reflection method's docblock.
   *
   * @param \ReflectionMethod $method
   *   The method whose docblock to read.
   *
   * @return array
   *   The normalized annotation model (see parseDocComment()).
   */
  public function parseMethod(\ReflectionMethod $method): array {
    $doc = $method->getDocComment();
    return $this->parseDocComment($doc === FALSE ? '' : (string) $doc);
  }

  /**
   * Parses a class docblock, whose tags apply to every method in the class.
   *
   * @param \ReflectionClass $class
   *   The class whose docblock to read.
   *
   * @return array
   *   The normalized annotation model (see parseDocComment()).
   */
  public function parseClass(\ReflectionClass $class): array {
    $doc = $class->getDocComment();
    return $this->parseDocComment($doc === FALSE ? '' : (string) $doc);
  }

  /**
   * Returns the empty annotation model.
   *
   * @return array
   *   A model with no annotations.
   */
  public function emptyModel(): array {
    return [
      'summary' => '',
      'description' => '',
      'category' => '',
      'tags' => [],
      'operation_id' => '',
      'security' => [],
      'deprecated' => FALSE,
      'deprecated_reason' => '',
      'internal' => FALSE,
      'params' => [],
      'request' => [],
      'request_example' => '',
      'responses' => [],
      'warnings' => [],
      'has_annotations' => FALSE,
    ];
  }

  /**
   * Parses a raw docblock string.
   *
   * @param string $doc
   *   The docblock text (with or without the surrounding comment syntax).
   *
   * @return array
   *   The normalized annotation model.
   */
  public function parseDocComment(string $doc): array {
    $result = $this->emptyModel();
    $prefix = $this->tagPrefix();
    if (strpos($doc, '@' . $prefix) === FALSE) {
      return $result;
    }
    $pattern = '/@' . preg_quote($prefix, '/') . '([A-Za-z]+)\s*(.*)$/';
    $currentStatus = NULL;
    foreach ($this->docLines($doc) as $line) {
      if (!preg_match($pattern, $line, $matches)) {
        continue;
      }
      $tag = strtolower($matches[1]);
      $rest = trim($matches[2]);
      $result['has_annotations'] = TRUE;

      switch ($tag) {
        case 'summary':
          $result['summary'] = $rest;
          break;

        case 'description':
          $result['description'] = $rest;
          break;

        case 'category':
          $result['category'] = $rest;
          break;

        case 'tag':
          if ($rest !== '') {
            $result['tags'][] = $rest;
          }
          break;

        case 'operationid':
          $result['operation_id'] = preg_replace('/[^A-Za-z0-9_]+/', '_', $rest);
          break;

        case 'security':
          $schemes = array_values(array_filter(preg_split('/[\s,]+/', $rest) ?: []));
          $result['security'] = array_map('strval', $schemes);
          break;

        case 'deprecated':
          $result['deprecated'] = TRUE;
          $result['deprecated_reason'] = $rest;
          break;

        case 'internal':
          $result['internal'] = TRUE;
          break;

        case 'param':
          $param = $this->parseParam($rest, $result['warnings']);
          if ($param) {
            $result['params'][] = $param;
          }
          break;

        case 'requestfield':
          $field = $this->parseField($rest, TRUE);
          if ($field) {
            $result['request'][] = $field;
          }
          else {
            $result['warnings'][] = $this->tagName('RequestField') . ': a field name is required.';
          }
          break;

        case 'requestexample':
          $result['request_example'] = $this->parseExample($rest, $result['warnings'], $this->tagName('RequestExample'));
          break;

        case 'response':
          $response = $this->parseResponse($rest, $result['warnings']);
          if ($response) {
            $status = $response['status'];
            $currentStatus = $status;
            $existing = $result['responses'][$status] ?? [];
            $result['responses'][$status] = [
              'description' => $response['description'],
              'component' => $response['component'],
              'fields' => $existing['fields'] ?? [],
              'example' => $existing['example'] ?? '',
            ];
          }
          break;

        case 'responsefield':
          $field = $this->parseField($rest, FALSE);
          if (!$field) {
            $result['warnings'][] = $this->tagName('ResponseField') . ': a field name is required.';
            break;
          }
          $status = $currentStatus ?? '200';
          $this->ensureResponse($result['responses'], $status);
          $result['responses'][$status]['fields'][] = $field;
          break;

        case 'example':
          [$machine, $json] = $this->splitFirstToken($rest);
          $status = preg_match('/^\d{3}$/', $machine) ? $machine : ($currentStatus ?? '200');
          $payload = preg_match('/^\d{3}$/', $machine) ? $json : $rest;
          $this->ensureResponse($result['responses'], $status);
          $result['responses'][$status]['example'] = $this->parseExample($payload, $result['warnings'], $this->tagName('Example'));
          break;

        default:
          $result['warnings'][] = 'Unknown annotation tag "@' . $prefix . $matches[1] . '".';
      }
    }

    $result['warnings'] = array_merge($result['warnings'], $this->validateComponents($result['responses']));
    return $result;
  }

  /**
   * Merges a class-level model into a method-level one.
   *
   * The method-level annotations win; class-level values fill the gaps. Lists
   * are merged so a class can declare shared parameters or errors once.
   *
   * @param array $class
   *   The class-level model.
   * @param array $method
   *   The method-level model.
   *
   * @return array
   *   The merged model.
   */
  public function merge(array $class, array $method): array {
    if (empty($class['has_annotations'])) {
      return $method;
    }
    $merged = $method;
    foreach (['summary', 'description', 'category', 'operation_id', 'request_example'] as $key) {
      if (($merged[$key] ?? '') === '') {
        $merged[$key] = $class[$key] ?? '';
      }
    }
    if (empty($merged['security'])) {
      $merged['security'] = $class['security'] ?? [];
    }
    if (empty($merged['deprecated']) && !empty($class['deprecated'])) {
      $merged['deprecated'] = TRUE;
      $merged['deprecated_reason'] = $class['deprecated_reason'] ?? '';
    }
    $merged['internal'] = !empty($merged['internal']) || !empty($class['internal']);
    $merged['tags'] = array_values(array_unique(array_merge($class['tags'] ?? [], $merged['tags'] ?? [])));
    $merged['params'] = $this->mergeNamedList($class['params'] ?? [], $merged['params'] ?? [], 'in');
    $merged['request'] = $this->mergeNamedList($class['request'] ?? [], $merged['request'] ?? [], NULL);
    foreach ($class['responses'] ?? [] as $status => $response) {
      if (!isset($merged['responses'][$status])) {
        $merged['responses'][$status] = $response;
      }
    }
    $merged['warnings'] = array_merge($class['warnings'] ?? [], $merged['warnings'] ?? []);
    $merged['has_annotations'] = TRUE;
    return $merged;
  }

  /**
   * Merges two lists of named descriptors, letting the second list win.
   *
   * @param array $base
   *   The lower-priority list.
   * @param array $override
   *   The higher-priority list.
   * @param string|null $extraKey
   *   An additional descriptor key forming part of the identity, or NULL.
   *
   * @return array
   *   The merged list.
   */
  protected function mergeNamedList(array $base, array $override, ?string $extraKey): array {
    $out = [];
    foreach (array_merge($base, $override) as $item) {
      $key = (string) ($item['name'] ?? '');
      if ($extraKey !== NULL) {
        $key = (string) ($item[$extraKey] ?? '') . '|' . $key;
      }
      $out[$key] = $item;
    }
    return array_values($out);
  }

  /**
   * Ensures a response entry exists for a status.
   *
   * @param array $responses
   *   The response map, by reference.
   * @param string $status
   *   The status code.
   */
  protected function ensureResponse(array &$responses, string $status): void {
    if (!isset($responses[$status])) {
      $responses[$status] = [
        'description' => '',
        'component' => NULL,
        'fields' => [],
        'example' => '',
      ];
    }
  }

  /**
   * Warns about response annotations referencing an unknown component.
   *
   * @param array $responses
   *   The parsed response map.
   *
   * @return string[]
   *   Warning messages.
   */
  protected function validateComponents(array $responses): array {
    $warnings = [];
    foreach ($responses as $status => $response) {
      $component = $response['component'] ?? NULL;
      if ($component !== NULL && !$this->components->has((string) $component)) {
        $warnings[] = $this->tagName('Response') . ' ' . $status . ': unknown component "' . $component . '".';
      }
    }
    return $warnings;
  }

  /**
   * Splits a docblock into clean content lines (comment markers stripped).
   *
   * @param string $doc
   *   The raw docblock.
   *
   * @return string[]
   *   The content lines.
   */
  protected function docLines(string $doc): array {
    $lines = preg_split('/\r\n|\r|\n/', $doc) ?: [];
    $out = [];
    foreach ($lines as $line) {
      $line = trim($line);
      // Strip the leading "/**", "*/", and "*" comment markers.
      $line = preg_replace('#^/\*\*+#', '', $line);
      $line = preg_replace('#\*/\s*$#', '', (string) $line);
      $line = preg_replace('/^\*\s?/', '', (string) $line);
      $out[] = trim((string) $line);
    }
    return $out;
  }

  /**
   * Splits "<machine tokens> - <description>" into [tokens, description].
   *
   * @param string $rest
   *   The tag body.
   *
   * @return array
   *   [string $machine, string $description].
   */
  protected function splitDescription(string $rest): array {
    // The first " - " (space-dash-space) separates machine tokens from prose.
    $position = strpos($rest, ' - ');
    if ($position !== FALSE) {
      return [trim(substr($rest, 0, $position)), trim(substr($rest, $position + 3))];
    }
    return [trim($rest), ''];
  }

  /**
   * Splits a body into its first whitespace-delimited token and the remainder.
   *
   * @param string $rest
   *   The tag body.
   *
   * @return array
   *   [string $first, string $remainder].
   */
  protected function splitFirstToken(string $rest): array {
    $rest = trim($rest);
    $position = strcspn($rest, " \t");
    return [substr($rest, 0, $position), trim(substr($rest, $position))];
  }

  /**
   * Parses a parameter body: "<in> <name> <type> [required] - <description>".
   *
   * @param string $rest
   *   The tag body.
   * @param array $warnings
   *   The warning list, by reference.
   *
   * @return array|null
   *   The parameter descriptor, or NULL when unparseable.
   */
  protected function parseParam(string $rest, array &$warnings): ?array {
    [$machine, $description] = $this->splitDescription($rest);
    $tokens = preg_split('/\s+/', $machine) ?: [];
    if (count($tokens) < 2) {
      $warnings[] = $this->tagName('Param') . ': expected "<in> <name> <type>", got "' . $machine . '".';
      return NULL;
    }
    $in = strtolower($tokens[0]);
    if (!in_array($in, self::PARAM_LOCATIONS, TRUE)) {
      // Tolerate a missing location by defaulting to query, but say so.
      $warnings[] = $this->tagName('Param') . ': unknown location "' . $tokens[0] . '", assuming "query".';
      array_unshift($tokens, 'query');
      $in = 'query';
    }
    $name = $tokens[1] ?? '';
    if ($name === '') {
      return NULL;
    }
    $type = $tokens[2] ?? 'string';
    $required = in_array('required', array_map('strtolower', array_slice($tokens, 3)), TRUE)
      || ($in === 'path');
    return [
      'in' => $in,
      'name' => $name,
      'type' => $type,
      'required' => $required,
      'description' => $description,
    ];
  }

  /**
   * Parses a field body: "<name> <type> [required] - <description>".
   *
   * @param string $rest
   *   The tag body.
   * @param bool $allow_required
   *   Whether a trailing "required" flag is meaningful (request fields).
   *
   * @return array|null
   *   The field descriptor, or NULL when unparseable.
   */
  protected function parseField(string $rest, bool $allow_required): ?array {
    [$machine, $description] = $this->splitDescription($rest);
    $tokens = preg_split('/\s+/', $machine) ?: [];
    $name = $tokens[0] ?? '';
    if ($name === '') {
      return NULL;
    }
    $type = $tokens[1] ?? 'string';
    $required = $allow_required
      && in_array('required', array_map('strtolower', array_slice($tokens, 2)), TRUE);
    return [
      'name' => $name,
      'type' => $type,
      'required' => $required,
      'description' => $description,
    ];
  }

  /**
   * Parses a response body: "<status> [ComponentName] - <description>".
   *
   * @param string $rest
   *   The tag body.
   * @param array $warnings
   *   The warning list, by reference.
   *
   * @return array|null
   *   The response descriptor, or NULL when unparseable.
   */
  protected function parseResponse(string $rest, array &$warnings): ?array {
    [$machine, $description] = $this->splitDescription($rest);
    $tokens = preg_split('/\s+/', $machine) ?: [];
    $status = $tokens[0] ?? '';
    if ($status === '' || !preg_match('/^\d{3}$/', $status)) {
      $warnings[] = $this->tagName('Response') . ': expected a three-digit status code, got "' . $status . '".';
      return NULL;
    }
    $component = NULL;
    if (isset($tokens[1]) && $tokens[1] !== '') {
      $candidate = $tokens[1];
      if ($this->components->has($candidate)) {
        $component = $candidate;
      }
      elseif ($description === '') {
        // A single unknown token with no description is treated as prose.
        $description = $candidate;
      }
      else {
        // It sits where a component name belongs but names no component.
        $component = $candidate;
      }
    }
    return [
      'status' => $status,
      'component' => $component,
      'description' => $description,
    ];
  }

  /**
   * Validates and normalizes an inline JSON example.
   *
   * @param string $raw
   *   The raw JSON text.
   * @param array $warnings
   *   The warning list, by reference.
   * @param string $tag
   *   The tag name, for the warning message.
   *
   * @return string
   *   The pretty-printed JSON, or '' when it could not be decoded.
   */
  protected function parseExample(string $raw, array &$warnings, string $tag): string {
    $raw = trim($raw);
    if ($raw === '') {
      return '';
    }
    $decoded = json_decode($raw, TRUE);
    if ($decoded === NULL && strtolower($raw) !== 'null') {
      $warnings[] = $tag . ': the example is not valid JSON.';
      return '';
    }
    $json = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return $json === FALSE ? '' : $json;
  }

}
