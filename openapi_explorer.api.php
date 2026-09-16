<?php

/**
 * @file
 * Hooks provided by the OpenAPI Explorer module.
 */

/**
 * @addtogroup hooks
 * @{
 */

/**
 * Alters the reusable schema components referenced by response annotations.
 *
 * The module ships a small generic catalog. Add the envelopes your own
 * responses use here, and annotations can then reference them by name, for
 * example "@oapiResponse 200 ArticleListResponse - a page of articles".
 *
 * @param array $components
 *   Component definitions keyed by component name. Each definition is:
 *   - description: (string) What the component represents.
 *   - type: (string) 'object' or 'array'.
 *   - fields: (array) A list of ['name', 'type', 'required', 'description'].
 *     Field names may use dots and "[]" to describe nesting, and a type may
 *     reference another component with a leading "$".
 */
function hook_openapi_explorer_schema_components_alter(array &$components) {
  $components['ArticleListResponse'] = [
    'description' => 'A page of articles.',
    'type' => 'object',
    'fields' => [
      ['name' => 'data[].id', 'type' => 'integer', 'description' => 'The article node ID.'],
      ['name' => 'data[].title', 'type' => 'string', 'description' => 'The article title.'],
      ['name' => 'meta', 'type' => '$PaginationMeta', 'description' => 'Pagination metadata.'],
    ],
  ];
}

/**
 * Alters the documented endpoints after they have been built.
 *
 * Use this to overlay curated documentation onto the discovered set, to remove
 * endpoints that should not be published, or to add endpoints this module
 * cannot discover on its own.
 *
 * @param array $endpoints
 *   Endpoint fragments keyed by endpoint id. See ApiDocProviderInterface for
 *   the shape of a fragment.
 */
function hook_openapi_explorer_endpoints_alter(array &$endpoints) {
  // Hide an endpoint that is not meant to be public.
  unset($endpoints['my_module_internal_callback']);

  // Give another one a better summary than the code could provide.
  if (isset($endpoints['my_module_articles'])) {
    $endpoints['my_module_articles']['category'] = 'Content';
  }
}

/**
 * Names a fallback response component for an operation that has no annotation.
 *
 * Nothing is guessed by default, because an incorrect schema is more misleading
 * than an unspecified one. Implement this hook when your responses follow a
 * house convention that can be derived from the route or the class name.
 *
 * @param string|null $component
 *   The component name to use, or NULL to leave the response unspecified. Names
 *   that do not match a defined component are ignored.
 * @param array $context
 *   An associative array with:
 *   - row: (array) The discovered endpoint row.
 *   - resolved: (array) The reflector's resolution, including 'class'.
 *   - verb: (string) The HTTP verb.
 */
function hook_openapi_explorer_envelope_alter(&$component, array $context) {
  if (strpos($context['row']['path'], '/api/store/') === 0) {
    $component = 'PaginatedResponse';
  }
}

/**
 * @} End of "addtogroup hooks".
 */
