<?php

namespace Drupal\openapi_explorer\OpenApi;

use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Catalog of reusable schema components plus the loose-type to JSON-Schema map.
 *
 * Annotations reference a component by name instead of repeating its fields on
 * every operation - e.g. "@oapiResponse 200 ErrorResponse - not found" - and
 * the builder expands it here. The same definitions become the OpenAPI
 * components/schemas section.
 *
 * The catalog ships with a deliberately small, generic set. Sites and modules
 * add their own through hook_openapi_explorer_schema_components_alter().
 *
 * Definitions are plain arrays (name/type/description per field) so they render
 * directly in the documentation page, with a JSON-Schema projection for the raw
 * spec.
 */
class SchemaComponents {

  /**
   * The module handler, for the component alter hook.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The altered component catalog (lazy cache).
   *
   * @var array|null
   */
  protected $components = NULL;

  /**
   * Constructs the component catalog.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   */
  public function __construct(ModuleHandlerInterface $module_handler) {
    $this->moduleHandler = $module_handler;
  }

  /**
   * Returns all component definitions keyed by component name.
   *
   * Each definition is:
   * ['description' => string, 'type' => 'object'|'array',
   *  'fields' => [['name','type','description','required'], ...]].
   *
   * @return array
   *   The component catalog.
   */
  public function all(): array {
    if ($this->components !== NULL) {
      return $this->components;
    }
    $components = $this->defaults();
    $this->moduleHandler->alter('openapi_explorer_schema_components', $components);
    return $this->components = is_array($components) ? $components : [];
  }

  /**
   * The built-in, intentionally generic component definitions.
   *
   * @return array
   *   The default catalog.
   */
  protected function defaults(): array {
    return [
      'ErrorResponse' => [
        'description' => 'A standard error body returned on 4xx/5xx responses.',
        'type' => 'object',
        'fields' => [
          ['name' => 'message', 'type' => 'string', 'description' => 'A human-readable error message.'],
          ['name' => 'errors', 'type' => 'array', 'description' => 'Optional list of field-level validation errors.'],
        ],
      ],
      'PaginatedResponse' => [
        'description' => 'A paginated list envelope: the page of items plus its metadata.',
        'type' => 'object',
        'fields' => [
          ['name' => 'data', 'type' => 'array', 'description' => 'The list of result items for the current page.'],
          ['name' => 'meta', 'type' => '$PaginationMeta', 'description' => 'Pagination metadata.'],
        ],
      ],
      'PaginationMeta' => [
        'description' => 'Pagination metadata describing the current page of a list response.',
        'type' => 'object',
        'fields' => [
          ['name' => 'total', 'type' => 'integer', 'description' => 'Total number of items across all pages.'],
          ['name' => 'count', 'type' => 'integer', 'description' => 'Number of items in the current page.'],
          ['name' => 'page', 'type' => 'integer', 'description' => 'The current page index.'],
          ['name' => 'per_page', 'type' => 'integer', 'description' => 'Maximum number of items per page.'],
        ],
      ],
    ];
  }

  /**
   * Whether a component with the given name exists.
   *
   * @param string $name
   *   The component name.
   *
   * @return bool
   *   TRUE when the component is defined.
   */
  public function has(string $name): bool {
    return array_key_exists($name, $this->all());
  }

  /**
   * Returns a single component definition, or NULL when unknown.
   *
   * @param string $name
   *   The component name.
   *
   * @return array|null
   *   The definition, or NULL.
   */
  public function get(string $name): ?array {
    $all = $this->all();
    return $all[$name] ?? NULL;
  }

  /**
   * The list of known component names.
   *
   * @return string[]
   *   Component names.
   */
  public function names(): array {
    return array_keys($this->all());
  }

  /**
   * Projects the components into an OpenAPI 3 components/schemas array.
   *
   * @return array
   *   Map of component name to a JSON-Schema object.
   */
  public function toOpenApiSchemas(): array {
    $schemas = [];
    foreach ($this->all() as $name => $definition) {
      $schemas[$name] = $this->definitionToSchema($definition);
    }
    return $schemas;
  }

  /**
   * Converts one component definition to a JSON-Schema object.
   *
   * @param array $definition
   *   The component definition.
   *
   * @return array
   *   The JSON-Schema object.
   */
  protected function definitionToSchema(array $definition): array {
    $schema = $this->fieldsToSchema($definition['fields'] ?? []);
    if (($definition['type'] ?? 'object') === 'array') {
      $schema = ['type' => 'array', 'items' => $schema];
    }
    if (!empty($definition['description'])) {
      $schema['description'] = $definition['description'];
    }
    return $schema;
  }

  /**
   * Builds one JSON-Schema object from a flat list of field descriptors.
   *
   * Field names may describe a nested structure with dots and "[]":
   * "data.items[].id" places "id" inside an array of objects under
   * "data.items". Intermediate levels are created implicitly, so a nested
   * payload can be declared with one annotation line per leaf.
   *
   * @param array $fields
   *   Field descriptors, each ['name', 'type', 'required', 'description'].
   *
   * @return array
   *   The JSON-Schema object.
   */
  public function fieldsToSchema(array $fields): array {
    $tree = [];
    foreach ($fields as $field) {
      $this->insertField($tree, $field);
    }
    return $this->treeToSchema($tree);
  }

  /**
   * Inserts one field descriptor into the nesting tree.
   *
   * @param array $tree
   *   The tree being built, by reference.
   * @param array $field
   *   The field descriptor.
   */
  protected function insertField(array &$tree, array $field): void {
    $path = trim((string) ($field['name'] ?? ''));
    if ($path === '') {
      return;
    }
    $segments = explode('.', $path);
    $last = count($segments) - 1;
    $node = &$tree;
    foreach ($segments as $index => $segment) {
      $isArray = substr($segment, -2) === '[]';
      $key = $isArray ? substr($segment, 0, -2) : $segment;
      if ($key === '') {
        unset($node);
        return;
      }
      if (!isset($node[$key])) {
        $node[$key] = [
          'array' => $isArray,
          'children' => [],
          'field' => NULL,
          'required' => FALSE,
        ];
      }
      if ($isArray) {
        $node[$key]['array'] = TRUE;
      }
      if ($index === $last) {
        $node[$key]['field'] = $field;
        $node[$key]['required'] = !empty($field['required']);
      }
      $node = &$node[$key]['children'];
    }
    unset($node);
  }

  /**
   * Renders a nesting tree into a JSON-Schema object.
   *
   * @param array $tree
   *   The tree produced by insertField().
   *
   * @return array
   *   The JSON-Schema object.
   */
  protected function treeToSchema(array $tree): array {
    $properties = [];
    $required = [];
    foreach ($tree as $name => $node) {
      if ($node['children']) {
        $schema = $this->treeToSchema($node['children']);
        $description = (string) ($node['field']['description'] ?? '');
        if ($description !== '') {
          $schema['description'] = $description;
        }
      }
      else {
        $schema = $this->fieldToSchema($node['field'] ?? ['type' => 'object']);
      }
      if ($node['array']) {
        $schema = ['type' => 'array', 'items' => $schema];
      }
      $properties[$name] = $schema;
      if ($node['required']) {
        $required[] = $name;
      }
    }
    $schema = ['type' => 'object', 'properties' => $properties ?: new \stdClass()];
    if ($required) {
      $schema['required'] = $required;
    }
    return $schema;
  }

  /**
   * Maps a single field descriptor to a JSON-Schema fragment.
   *
   * @param array $field
   *   The field descriptor; the 'type' may name a component (directly or with a
   *   leading "$"), or use the extended type syntax documented on typeSchema().
   *
   * @return array
   *   The JSON-Schema fragment.
   */
  public function fieldToSchema(array $field): array {
    $type = (string) ($field['type'] ?? 'string');
    $component = $field['component'] ?? NULL;
    if ($component !== NULL && $this->has((string) $component)) {
      $type = '$' . $component;
    }
    $schema = $this->typeSchema($type);
    if (!empty($field['enum']) && is_array($field['enum']) && !isset($schema['$ref'])) {
      $schema['enum'] = array_values($field['enum']);
    }
    $description = (string) ($field['description'] ?? '');
    if ($description === '') {
      return $schema;
    }
    if (isset($schema['$ref'])) {
      // OpenAPI 3.0 ignores siblings of $ref; carry the description via allOf.
      return ['allOf' => [$schema], 'description' => $description];
    }
    $schema['description'] = $description;
    return $schema;
  }

  /**
   * Builds a JSON-Schema fragment from a loose type expression.
   *
   * Understands, in addition to the plain scalar names:
   *   - "$Component" or a bare known component name - a $ref;
   *   - "array<T>" - a typed array, where T is any type expression;
   *   - "T(a|b|c)" - an enumeration of the given values;
   *   - semantic aliases such as datetime, date, email, uri, uuid - a string
   *     with the matching OpenAPI "format".
   *
   * @param string $type
   *   The type expression.
   *
   * @return array
   *   The JSON-Schema fragment.
   */
  public function typeSchema(string $type): array {
    $type = trim($type);
    if ($type === '') {
      return ['type' => 'string'];
    }

    // Enumeration: "string(draft|published)".
    if (preg_match('/^([^(]+)\(([^)]*)\)$/', $type, $matches)) {
      $schema = $this->typeSchema(trim($matches[1]));
      $values = array_values(array_filter(array_map('trim', explode('|', $matches[2])), function ($value) {
        return $value !== '';
      }));
      if ($values && !isset($schema['$ref'])) {
        $schema['enum'] = $this->castEnum($values, $schema['type'] ?? 'string');
      }
      return $schema;
    }

    // Typed array: "array<string>", "array<ErrorResponse>".
    if (preg_match('/^array\s*<\s*(.+?)\s*>$/i', $type, $matches)) {
      return ['type' => 'array', 'items' => $this->typeSchema($matches[1])];
    }

    // Component reference, with or without the leading "$".
    $componentName = ($type[0] === '$') ? substr($type, 1) : $type;
    if ($componentName !== '' && $this->has($componentName)) {
      return ['$ref' => '#/components/schemas/' . $componentName];
    }

    $format = $this->formatFor($type);
    if ($format !== '') {
      return ['type' => 'string', 'format' => $format];
    }

    $schema = ['type' => self::normalizeType($type)];
    if ($schema['type'] === 'array') {
      $schema['items'] = new \stdClass();
    }
    return $schema;
  }

  /**
   * Casts enumeration values to the primitive type of their schema.
   *
   * @param string[] $values
   *   The raw values as written in the annotation.
   * @param string $type
   *   The JSON-Schema primitive type.
   *
   * @return array
   *   The cast values.
   */
  protected function castEnum(array $values, string $type): array {
    return array_map(function ($value) use ($type) {
      if ($type === 'integer') {
        return (int) $value;
      }
      if ($type === 'number') {
        return (float) $value;
      }
      if ($type === 'boolean') {
        return in_array(strtolower($value), ['1', 'true', 'yes'], TRUE);
      }
      return $value;
    }, $values);
  }

  /**
   * Maps a semantic type alias to an OpenAPI string format.
   *
   * @param string $type
   *   The type expression.
   *
   * @return string
   *   The format name, or '' when the type is not a known alias.
   */
  protected function formatFor(string $type): string {
    switch (strtolower(trim($type))) {
      case 'datetime':
      case 'timestamp':
        return 'date-time';

      case 'date':
        return 'date';

      case 'time':
        return 'time';

      case 'email':
        return 'email';

      case 'uri':
      case 'url':
        return 'uri';

      case 'uuid':
        return 'uuid';

      case 'password':
        return 'password';

      case 'binary':
      case 'file':
        return 'binary';

      case 'byte':
      case 'base64':
        return 'byte';

      case 'ipv4':
        return 'ipv4';

      case 'ipv6':
        return 'ipv6';
    }
    return '';
  }

  /**
   * Normalizes a loose type name to a JSON-Schema primitive type.
   *
   * @param string $type
   *   The type name.
   *
   * @return string
   *   One of string, integer, number, boolean, array, object.
   */
  public static function normalizeType(string $type): string {
    $normalized = strtolower(trim($type));
    switch ($normalized) {
      case 'int':
      case 'integer':
      case 'long':
        return 'integer';

      case 'float':
      case 'double':
      case 'decimal':
      case 'number':
        return 'number';

      case 'bool':
      case 'boolean':
        return 'boolean';

      case 'array':
      case 'list':
        return 'array';

      case 'object':
      case 'map':
      case 'json':
        return 'object';

      case '':
        return 'string';
    }
    // Unknown names fall back to string for the primitive spec.
    return in_array($normalized, ['string', 'integer', 'number', 'boolean', 'array', 'object'], TRUE)
      ? $normalized
      : 'string';
  }

  /**
   * Builds a sample payload from a list of field descriptors.
   *
   * Used to prefill the request-body editor in the interactive tester, so the
   * sample follows the same nesting the annotations declare.
   *
   * @param array $fields
   *   Field descriptors.
   *
   * @return mixed
   *   A representative payload.
   */
  public function sampleForFields(array $fields) {
    return $this->sampleForSchema($this->fieldsToSchema($fields));
  }

  /**
   * Builds a representative sample value for a JSON-Schema fragment.
   *
   * @param array $schema
   *   The JSON-Schema fragment.
   * @param int $depth
   *   Current recursion depth; guards against self-referential components.
   *
   * @return mixed
   *   A representative value.
   */
  public function sampleForSchema(array $schema, int $depth = 0) {
    if ($depth > 6) {
      return NULL;
    }
    if (isset($schema['$ref'])) {
      $name = (string) substr($schema['$ref'], (int) strrpos($schema['$ref'], '/') + 1);
      $definition = $this->get($name);
      return $definition
        ? $this->sampleForSchema($this->definitionToSchema($definition), $depth + 1)
        : new \stdClass();
    }
    if (!empty($schema['allOf']) && is_array($schema['allOf'])) {
      $merged = [];
      foreach ($schema['allOf'] as $part) {
        $sample = is_array($part) ? $this->sampleForSchema($part, $depth + 1) : NULL;
        if (is_array($sample)) {
          $merged = array_merge($merged, $sample);
        }
      }
      return $merged ?: new \stdClass();
    }
    if (!empty($schema['enum']) && is_array($schema['enum'])) {
      return reset($schema['enum']);
    }

    switch ($schema['type'] ?? 'string') {
      case 'object':
        $properties = $schema['properties'] ?? [];
        if (!is_array($properties) || !$properties) {
          return new \stdClass();
        }
        $out = [];
        foreach ($properties as $name => $property) {
          $out[$name] = is_array($property) ? $this->sampleForSchema($property, $depth + 1) : NULL;
        }
        return $out;

      case 'array':
        $items = $schema['items'] ?? NULL;
        return (is_array($items) && $items) ? [$this->sampleForSchema($items, $depth + 1)] : [];

      case 'integer':
      case 'number':
        return 0;

      case 'boolean':
        return TRUE;
    }

    switch ($schema['format'] ?? '') {
      case 'date-time':
        return '1970-01-01T00:00:00+00:00';

      case 'date':
        return '1970-01-01';

      case 'email':
        return 'user@example.com';

      case 'uri':
        return 'https://example.com';

      case 'uuid':
        return '00000000-0000-0000-0000-000000000000';
    }
    return 'string';
  }

}
