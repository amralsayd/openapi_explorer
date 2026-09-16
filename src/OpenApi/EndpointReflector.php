<?php

namespace Drupal\openapi_explorer\OpenApi;

use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Core\Routing\RouteProviderInterface;

/**
 * Resolves the PHP class/method backing an endpoint row and reads its code.
 *
 * A discovered row carries a path and a set of HTTP methods but not the class
 * that serves it. This reflector rebuilds that link two ways:
 *   - REST resources: from the REST plugin definitions' uri_paths (the path
 *     template to the plugin class); the verb maps to the plugin's
 *     get/post/patch/delete method.
 *   - Controllers: from the router (path to route _controller), giving a single
 *     Class::method that serves the row.
 *
 * From the resolved method it derives path parameters (the {tokens} in the
 * path) and a best-effort list of query keys (a static scan for
 * $request->query->get('x')). Everything here is best-effort and non-fatal: an
 * unresolved row simply yields an empty scaffold.
 */
class EndpointReflector {

  /**
   * Largest source file, in bytes, that will be scanned for query keys.
   *
   * Guards against reading very large files when scanning a whole site.
   */
  const MAX_SOURCE_BYTES = 2097152;

  /**
   * The REST resource plugin manager (optional; rest module may be disabled).
   *
   * @var \Drupal\Component\Plugin\PluginManagerInterface|null
   */
  protected $restManager;

  /**
   * The route provider.
   *
   * @var \Drupal\Core\Routing\RouteProviderInterface
   */
  protected $routeProvider;

  /**
   * Lazily-built map of REST uri_path template to plugin class FQCN.
   *
   * @var array|null
   */
  protected $restByPath = NULL;

  /**
   * Lazily-built map of route path to ['class', 'method', 'controller'].
   *
   * @var array|null
   */
  protected $ctrlByPath = NULL;

  /**
   * Reflection cache keyed by "Class::method".
   *
   * @var array
   */
  protected $methodCache = [];

  /**
   * Class reflection cache keyed by class name.
   *
   * @var array
   */
  protected $classCache = [];

  /**
   * Query-key scan cache keyed by "Class::method".
   *
   * @var array
   */
  protected $queryKeyCache = [];

  /**
   * Constructs the reflector.
   *
   * @param \Drupal\Component\Plugin\PluginManagerInterface|null $rest_manager
   *   The REST resource plugin manager, or NULL when rest is not installed.
   * @param \Drupal\Core\Routing\RouteProviderInterface $route_provider
   *   The route provider.
   */
  public function __construct(?PluginManagerInterface $rest_manager, RouteProviderInterface $route_provider) {
    $this->restManager = $rest_manager;
    $this->routeProvider = $route_provider;
  }

  /**
   * Resolves an endpoint row to its backing class/method.
   *
   * @param array $row
   *   An endpoint row (needs at least a 'path').
   *
   * @return array
   *   ['type' => 'rest'|'controller'|'none', 'class' => string,
   *    'method' => string, 'path_params' => string[]].
   */
  public function resolve(array $row): array {
    $path = (string) ($row['path'] ?? '');
    $result = [
      'type' => 'none',
      'class' => '',
      'method' => '',
      'path_params' => $this->pathParams($path),
    ];
    if ($path === '') {
      return $result;
    }
    $rest = $this->restMap();
    if (isset($rest[$path])) {
      $result['type'] = 'rest';
      $result['class'] = $rest[$path];
      return $result;
    }
    $controllers = $this->controllerMap();
    if (isset($controllers[$path])) {
      $result['type'] = 'controller';
      $result['class'] = (string) ($controllers[$path]['class'] ?? '');
      $result['method'] = (string) ($controllers[$path]['method'] ?? '');
    }
    return $result;
  }

  /**
   * Returns the ReflectionMethod serving a given verb for a resolved row.
   *
   * @param array $resolved
   *   The array returned by resolve().
   * @param string $verb
   *   The HTTP verb (GET, POST, PATCH, DELETE...).
   *
   * @return \ReflectionMethod|null
   *   The method, or NULL when it cannot be reflected.
   */
  public function methodFor(array $resolved, string $verb): ?\ReflectionMethod {
    $class = (string) ($resolved['class'] ?? '');
    if ($class === '' || !class_exists($class)) {
      return NULL;
    }
    if (($resolved['type'] ?? '') === 'rest') {
      return $this->reflect($class, strtolower($verb));
    }
    if (($resolved['type'] ?? '') === 'controller') {
      $name = (string) ($resolved['method'] ?? '');
      return $name !== '' ? $this->reflect($class, $name) : NULL;
    }
    return NULL;
  }

  /**
   * Returns the ReflectionClass of a resolved row, for class-level annotations.
   *
   * @param array $resolved
   *   The array returned by resolve().
   *
   * @return \ReflectionClass|null
   *   The class, or NULL when it cannot be reflected.
   */
  public function classFor(array $resolved): ?\ReflectionClass {
    $class = (string) ($resolved['class'] ?? '');
    if ($class === '') {
      return NULL;
    }
    if (array_key_exists($class, $this->classCache)) {
      return $this->classCache[$class];
    }
    $reflection = NULL;
    try {
      if (class_exists($class)) {
        $reflection = new \ReflectionClass($class);
      }
    }
    catch (\Throwable $e) {
      $reflection = NULL;
    }
    return $this->classCache[$class] = $reflection;
  }

  /**
   * Extracts the {token} path parameters from a path template.
   *
   * @param string $path
   *   The path template.
   *
   * @return string[]
   *   The parameter names in order of appearance.
   */
  public function pathParams(string $path): array {
    if (preg_match_all('/\{([^}]+)\}/', $path, $matches)) {
      return array_values(array_unique($matches[1]));
    }
    return [];
  }

  /**
   * Best-effort scan of a method body for query-string keys it reads.
   *
   * Matches $request->query->get('x') / ->query->has('x') style access. Static
   * only (no execution); annotations are the source of truth when present.
   *
   * @param \ReflectionMethod|null $method
   *   The method to scan.
   *
   * @return string[]
   *   Distinct query key names.
   */
  public function queryKeys(?\ReflectionMethod $method): array {
    if (!$method) {
      return [];
    }
    $key = $method->getDeclaringClass()->getName() . '::' . $method->getName();
    if (isset($this->queryKeyCache[$key])) {
      return $this->queryKeyCache[$key];
    }
    $keys = [];
    $source = $this->methodSource($method);
    if ($source !== '' && preg_match_all('/->query->(?:get|has)\(\s*[\'"]([A-Za-z0-9_\-.]+)[\'"]/', $source, $matches)) {
      foreach ($matches[1] as $name) {
        $keys[$name] = TRUE;
      }
    }
    return $this->queryKeyCache[$key] = array_keys($keys);
  }

  /**
   * Builds (once) the REST uri_path to class map from plugin definitions.
   *
   * @return array
   *   Map of path template to class name.
   */
  protected function restMap(): array {
    if ($this->restByPath !== NULL) {
      return $this->restByPath;
    }
    $map = [];
    if ($this->restManager) {
      foreach ($this->restManager->getDefinitions() as $definition) {
        $class = $definition['class'] ?? NULL;
        $uriPaths = $definition['uri_paths'] ?? [];
        if (!$class || !is_array($uriPaths)) {
          continue;
        }
        foreach ($uriPaths as $template) {
          if (is_string($template) && $template !== '') {
            // uri_paths templates may omit the leading slash; register both the
            // raw and slash-normalised form so lookups match either.
            $map[$template] = $class;
            $map['/' . ltrim($template, '/')] = $class;
          }
        }
      }
    }
    return $this->restByPath = $map;
  }

  /**
   * Builds (once) the controller route path to class/method map.
   *
   * @return array
   *   Map of route path to ['class', 'method', 'controller'].
   */
  protected function controllerMap(): array {
    if ($this->ctrlByPath !== NULL) {
      return $this->ctrlByPath;
    }
    $map = [];
    foreach ($this->routeProvider->getAllRoutes() as $route) {
      if ($route->hasDefault('_rest_resource_config')) {
        // Handled via the REST plugin map instead.
        continue;
      }
      $controller = $route->getDefault('_controller');
      if (!is_string($controller) || $controller === '') {
        continue;
      }
      $path = $route->getPath();
      if (isset($map[$path])) {
        continue;
      }
      $map[$path] = $this->parseController($controller);
    }
    return $this->ctrlByPath = $map;
  }

  /**
   * Parses a route _controller into a class/method pair when possible.
   *
   * Handles "Class::method"; service-based "service_id:method" cannot be
   * reflected here (no container), so only the raw string is preserved.
   *
   * @param string $controller
   *   The route's _controller default.
   *
   * @return array
   *   ['class', 'method', 'controller'].
   */
  protected function parseController(string $controller): array {
    $out = ['class' => '', 'method' => '', 'controller' => $controller];
    if (strpos($controller, '::') !== FALSE) {
      [$class, $method] = explode('::', $controller, 2);
      $out['class'] = ltrim($class, '\\');
      $out['method'] = $method;
    }
    return $out;
  }

  /**
   * Reflects a class method, caching results and swallowing failures.
   *
   * @param string $class
   *   The class name.
   * @param string $method
   *   The method name.
   *
   * @return \ReflectionMethod|null
   *   The method, or NULL.
   */
  protected function reflect(string $class, string $method): ?\ReflectionMethod {
    $key = $class . '::' . $method;
    if (array_key_exists($key, $this->methodCache)) {
      return $this->methodCache[$key];
    }
    $reflection = NULL;
    try {
      if (method_exists($class, $method)) {
        $reflection = new \ReflectionMethod($class, $method);
      }
    }
    catch (\Throwable $e) {
      $reflection = NULL;
    }
    return $this->methodCache[$key] = $reflection;
  }

  /**
   * Reads the source text of a reflected method (best-effort).
   *
   * @param \ReflectionMethod $method
   *   The method whose body to read.
   *
   * @return string
   *   The source text, or '' when it cannot be read.
   */
  protected function methodSource(\ReflectionMethod $method): string {
    $file = $method->getFileName();
    $start = $method->getStartLine();
    $end = $method->getEndLine();
    if ($file === FALSE || !is_file($file) || !$start || !$end || $end < $start) {
      return '';
    }
    $size = @filesize($file);
    if ($size === FALSE || $size > self::MAX_SOURCE_BYTES) {
      return '';
    }
    try {
      $lines = @file($file, FILE_IGNORE_NEW_LINES);
    }
    catch (\Throwable $e) {
      return '';
    }
    if ($lines === FALSE) {
      return '';
    }
    return implode("\n", array_slice($lines, $start - 1, $end - $start + 1));
  }

}
