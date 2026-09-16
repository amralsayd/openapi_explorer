<?php

namespace Drupal\openapi_explorer\Doc;

/**
 * Read access to the generated documentation, one endpoint at a time.
 *
 * Modules that want to show API documentation inside their own UI depend on
 * this interface rather than on the builder implementation, and should inject
 * it optionally ("@?openapi_explorer.builder") so they keep working when this
 * module is not installed.
 *
 * An endpoint fragment is an array shaped:
 * @code
 * [
 *   'id' => string,            // Stable endpoint id.
 *   'path' => string,          // Route path, with {token} placeholders.
 *   'module' => string,        // Machine name of the owning module.
 *   'source' => string,        // custom, contrib, profile or core.
 *   'group' => string,         // 'rest' or 'controller'.
 *   'category' => string,      // Documentation grouping.
 *   'description' => string,
 *   'deprecated' => bool,
 *   'auth' => string[],        // Authentication provider ids.
 *   'class' => string,         // Backing class, when resolved.
 *   'class_method' => string,
 *   'resolved' => bool,        // Whether a backing class was found.
 *   'coverage' => string,      // 'annotated', 'partial' or 'none'.
 *   'operations' => array,     // Keyed by HTTP verb.
 * ]
 * @endcode
 */
interface ApiDocProviderInterface {

  /**
   * Returns the documentation fragment for one endpoint.
   *
   * @param string $endpointId
   *   The endpoint id, as reported by the documentation page.
   *
   * @return array|null
   *   The endpoint fragment, or NULL when the id is unknown.
   */
  public function forEndpoint(string $endpointId): ?array;

}
