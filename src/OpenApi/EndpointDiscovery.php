<?php

namespace Drupal\openapi_explorer\OpenApi;

use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Routing\RouteProviderInterface;
use Symfony\Component\Routing\Route;

/**
 * Discovers the HTTP endpoints the site exposes, straight from the router.
 *
 * This is what makes the module self-contained: it enumerates endpoints
 * from the route collection (and the REST plugin manager) instead of requiring
 * an external manifest. Two kinds of routes are considered:
 *   - REST resources: routes carrying "_rest_resource_config", whose provider
 *     module comes from the resource plugin definition;
 *   - controllers: routes served by a controller, whose owning module is read
 *     from the controller's namespace.
 *
 * Which of those are reported is entirely configuration-driven
 * (openapi_explorer.settings): the path prefixes a route must match, which
 * extension sources (custom, contrib, profile, core) to include, an optional
 * per-module allow list, and whether administrative routes count. Leaving the
 * prefix list empty and ticking every source documents every route on the site.
 *
 * Each discovered endpoint is normalised to the row shape the builder consumes:
 *   ['id', 'path', 'module', 'group', 'methods', 'auth'].
 */
class EndpointDiscovery {

  /**
   * HTTP verbs documented, in canonical display order.
   */
  const METHOD_ORDER = ['GET', 'POST', 'PATCH', 'PUT', 'DELETE'];

  /**
   * The extension sources a module can belong to.
   */
  const SOURCES = ['custom', 'contrib', 'profile', 'core'];

  /**
   * The REST resource plugin manager (NULL when the rest module is disabled).
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
   * The entity type manager (loads rest_resource_config entities).
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The module extension list (module source detection).
   *
   * @var \Drupal\Core\Extension\ModuleExtensionList
   */
  protected $moduleList;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Discovered rows keyed by id (lazy cache).
   *
   * @var array|null
   */
  protected $rows = NULL;

  /**
   * Whether the last discovery hit the configured endpoint cap.
   *
   * @var bool
   */
  protected $capped = FALSE;

  /**
   * Static cache: module machine name to its extension source.
   *
   * @var string[]
   */
  protected $sourceCache = [];

  /**
   * Static cache of loaded rest_resource_config entities, keyed by config id.
   *
   * @var array
   */
  protected $restConfigCache = [];

  /**
   * Constructs the discovery service.
   *
   * @param \Drupal\Component\Plugin\PluginManagerInterface|null $rest_manager
   *   The REST resource plugin manager, or NULL when rest is not installed.
   * @param \Drupal\Core\Routing\RouteProviderInterface $route_provider
   *   The route provider.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Extension\ModuleExtensionList $module_list
   *   The module extension list.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(?PluginManagerInterface $rest_manager, RouteProviderInterface $route_provider, EntityTypeManagerInterface $entity_type_manager, ModuleExtensionList $module_list, ConfigFactoryInterface $config_factory) {
    $this->restManager = $rest_manager;
    $this->routeProvider = $route_provider;
    $this->entityTypeManager = $entity_type_manager;
    $this->moduleList = $module_list;
    $this->configFactory = $config_factory;
  }

  /**
   * Returns the discovered endpoint rows, keyed by id.
   *
   * @return array[]
   *   Rows shaped ['id', 'path', 'module', 'group', 'methods', 'auth'].
   */
  public function inboundRows(): array {
    if ($this->rows !== NULL) {
      return $this->rows;
    }
    $this->rows = [];
    $groups = $this->discover();

    // Map plugin id to its paths, to tell canonical from create paths.
    $pluginPaths = [];
    foreach ($groups as $path => $group) {
      if ($group['plugin'] !== '') {
        $pluginPaths[$group['plugin']][] = $path;
      }
    }

    $usedIds = [];
    foreach ($groups as $path => $group) {
      $methods = $this->orderMethods(array_keys($group['methods']));
      if (!$methods) {
        continue;
      }
      $id = $this->uniqueId($this->deriveId($group, $methods, $pluginPaths), $usedIds);
      $usedIds[$id] = TRUE;
      $this->rows[$id] = [
        'id' => $id,
        'path' => $path,
        'module' => $group['module'],
        'source' => $group['source'],
        'group' => $group['group'],
        'methods' => $methods,
        'auth' => array_values($group['auth']),
      ];
    }
    return $this->rows;
  }

  /**
   * Whether discovery stopped early because the endpoint cap was reached.
   *
   * @return bool
   *   TRUE when the result is truncated.
   */
  public function isCapped(): bool {
    $this->inboundRows();
    return $this->capped;
  }

  /**
   * Returns every installed module keyed by machine name, grouped by source.
   *
   * Used by the settings form to offer a per-module scanning checklist.
   *
   * @return array
   *   Map of source name to [module machine name => human name].
   */
  public function modulesBySource(): array {
    $grouped = array_fill_keys(self::SOURCES, []);
    foreach ($this->moduleList->getList() as $name => $extension) {
      $source = $this->moduleSource($name);
      $info = $extension->info ?? [];
      $grouped[$source][$name] = (string) ($info['name'] ?? $name);
    }
    foreach ($grouped as &$modules) {
      asort($modules);
    }
    return $grouped;
  }

  /**
   * Discovers endpoints from the route collection.
   *
   * @return array
   *   Groups keyed by path.
   */
  protected function discover(): array {
    $settings = $this->configFactory->get('openapi_explorer.settings');
    $prefixes = $this->normalizePrefixes((array) ($settings->get('scan.path_prefixes') ?? []));
    $sources = array_filter((array) ($settings->get('scan.sources') ?? []));
    $allowed = array_filter((array) ($settings->get('scan.modules') ?? []));
    $excluded = array_flip(array_filter((array) ($settings->get('scan.excluded_modules') ?? [])));
    $includeRest = (bool) ($settings->get('scan.include_rest_resources') ?? TRUE);
    $includeAdmin = (bool) ($settings->get('scan.include_admin_routes') ?? FALSE);
    $max = (int) ($settings->get('scan.max_endpoints') ?? 2000);
    $allowed = $allowed ? array_flip($allowed) : [];

    $this->capped = FALSE;
    $groups = [];
    foreach ($this->routeProvider->getAllRoutes() as $name => $route) {
      /** @var \Symfony\Component\Routing\Route $route */
      if ($max > 0 && count($groups) >= $max) {
        $this->capped = TRUE;
        break;
      }
      $path = (string) $route->getPath();
      $configId = (string) ($route->getDefault('_rest_resource_config') ?? '');
      $isRest = $configId !== '';
      if ($isRest && !$includeRest) {
        continue;
      }
      if (!$includeAdmin && $route->getOption('_admin_route')) {
        continue;
      }
      // REST resource routes are always in scope for their prefix check too, so
      // a site can narrow REST endpoints the same way as controller routes.
      if (!$this->matchesPrefix($path, $prefixes)) {
        continue;
      }

      if ($isRest) {
        $plugin = $this->restPluginId($configId);
        $module = $plugin !== '' ? $this->restProvider($plugin) : '';
        $groupLabel = 'rest';
      }
      else {
        $plugin = '';
        $module = $this->controllerModule($route);
        $groupLabel = 'controller';
      }
      if ($module === '' || isset($excluded[$module])) {
        continue;
      }
      if ($allowed) {
        if (!isset($allowed[$module])) {
          continue;
        }
      }
      elseif (!in_array($this->moduleSource($module), $sources, TRUE)) {
        continue;
      }

      $methods = $this->normalizeMethods($route->getMethods());
      $auth = array_values(array_filter((array) ($route->getOption('_auth') ?? [])));

      if (!isset($groups[$path])) {
        $groups[$path] = [
          'module' => $module,
          'source' => $this->moduleSource($module),
          'path' => $path,
          'methods' => [],
          'auth' => [],
          'group' => $groupLabel,
          'plugin' => $plugin,
          'name' => $name,
        ];
      }
      foreach ($methods as $method) {
        $groups[$path]['methods'][$method] = $method;
      }
      foreach ($auth as $provider) {
        $groups[$path]['auth'][$provider] = $provider;
      }
    }
    return $groups;
  }

  /**
   * Normalizes the configured path prefixes.
   *
   * @param array $prefixes
   *   The raw configured prefixes.
   *
   * @return string[]
   *   Prefixes with a leading slash and no trailing slash.
   */
  protected function normalizePrefixes(array $prefixes): array {
    $out = [];
    foreach ($prefixes as $prefix) {
      $prefix = trim((string) $prefix);
      if ($prefix === '') {
        continue;
      }
      $out[] = '/' . trim($prefix, '/');
    }
    return $out;
  }

  /**
   * Whether a path matches one of the configured prefixes.
   *
   * An empty prefix list matches every path, which is how a site documents all
   * of its routes rather than a single API namespace.
   *
   * @param string $path
   *   The route path.
   * @param string[] $prefixes
   *   The normalized prefixes.
   *
   * @return bool
   *   TRUE when the path is in scope.
   */
  protected function matchesPrefix(string $path, array $prefixes): bool {
    if (!$prefixes) {
      return TRUE;
    }
    foreach ($prefixes as $prefix) {
      if ($path === $prefix || strpos($path, $prefix . '/') === 0) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Classifies a module into one of the known extension sources.
   *
   * @param string $module
   *   The module machine name.
   *
   * @return string
   *   One of 'core', 'contrib', 'profile', 'custom'.
   */
  public function moduleSource(string $module): string {
    if (isset($this->sourceCache[$module])) {
      return $this->sourceCache[$module];
    }
    $source = 'custom';
    try {
      $path = $this->moduleList->getPath($module);
      if (strpos($path, 'core/') === 0) {
        $source = 'core';
      }
      elseif (strpos($path, 'profiles/') === 0) {
        $source = 'profile';
      }
      elseif (preg_match('#(^|/)contrib(/|$)#', $path)) {
        $source = 'contrib';
      }
    }
    catch (\Throwable $e) {
      $source = 'custom';
    }
    return $this->sourceCache[$module] = $source;
  }

  /**
   * Derives a stable id for a discovered group.
   *
   * @param array $group
   *   The discovered group.
   * @param string[] $methods
   *   The group's HTTP methods.
   * @param array $pluginPaths
   *   Map of plugin id to its paths.
   *
   * @return string
   *   The endpoint id.
   */
  protected function deriveId(array $group, array $methods, array $pluginPaths): string {
    if ($group['plugin'] !== '') {
      $pluginId = $group['plugin'];
      // When a plugin exposes more than one path, the POST-only path is the
      // "create" path and gets a _create suffix.
      if (count($pluginPaths[$pluginId] ?? []) > 1 && $methods === ['POST']) {
        return $pluginId . '_create';
      }
      return $pluginId;
    }
    return (string) preg_replace('/[^a-z0-9_]+/', '_', strtolower($group['name'] ?: $group['path']));
  }

  /**
   * Ensures an id is unique against the set of already-used ids.
   *
   * @param string $id
   *   The candidate id.
   * @param array $used
   *   Map of ids already taken.
   *
   * @return string
   *   A unique id.
   */
  protected function uniqueId(string $id, array $used): string {
    if (!isset($used[$id])) {
      return $id;
    }
    $suffix = 2;
    while (isset($used[$id . '_' . $suffix])) {
      $suffix++;
    }
    return $id . '_' . $suffix;
  }

  /**
   * Filters a route's methods down to the verbs documented, uppercased.
   *
   * A route that declares no methods accepts any verb; GET is documented as the
   * representative one rather than emitting an operation for all five.
   *
   * @param array $methods
   *   The route's declared methods.
   *
   * @return string[]
   *   The normalized methods.
   */
  protected function normalizeMethods(array $methods): array {
    $out = [];
    foreach ($methods as $method) {
      $method = strtoupper((string) $method);
      if (in_array($method, self::METHOD_ORDER, TRUE)) {
        $out[$method] = $method;
      }
    }
    return $out ? array_values($out) : ['GET'];
  }

  /**
   * Orders a set of methods by METHOD_ORDER.
   *
   * @param array $methods
   *   The methods to order.
   *
   * @return string[]
   *   The ordered methods.
   */
  protected function orderMethods(array $methods): array {
    $out = [];
    foreach (self::METHOD_ORDER as $method) {
      if (in_array($method, $methods, TRUE)) {
        $out[] = $method;
      }
    }
    return $out;
  }

  /**
   * Extracts the defining module from a route's controller/form callable.
   *
   * @param \Symfony\Component\Routing\Route $route
   *   The route.
   *
   * @return string
   *   The module machine name, or '' when it cannot be determined.
   */
  protected function controllerModule(Route $route): string {
    $callable = (string) (
      $route->getDefault('_controller')
      ?? $route->getDefault('_form')
      ?? $route->getDefault('_entity_form')
      ?? ''
    );
    if ($callable !== '' && preg_match('/^\\\\?Drupal\\\\([a-z0-9_]+)\\\\/', $callable, $matches)) {
      return $matches[1];
    }
    return '';
  }

  /**
   * Loads a rest_resource_config entity (cached), or NULL when unavailable.
   *
   * @param string $config_id
   *   The rest_resource_config entity id.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The entity, or NULL.
   */
  protected function restConfig(string $config_id) {
    if (array_key_exists($config_id, $this->restConfigCache)) {
      return $this->restConfigCache[$config_id];
    }
    $entity = NULL;
    try {
      if ($this->entityTypeManager->hasDefinition('rest_resource_config')) {
        $entity = $this->entityTypeManager->getStorage('rest_resource_config')->load($config_id);
      }
    }
    catch (\Throwable $e) {
      $entity = NULL;
    }
    return $this->restConfigCache[$config_id] = $entity;
  }

  /**
   * Resolves the REST resource plugin id for a rest_resource_config id.
   *
   * @param string $config_id
   *   The rest_resource_config entity id.
   *
   * @return string
   *   The plugin id, or ''.
   */
  protected function restPluginId(string $config_id): string {
    $config = $this->restConfig($config_id);
    if (!$config) {
      return '';
    }
    if (method_exists($config, 'getResourcePluginId')) {
      return (string) $config->getResourcePluginId();
    }
    if (method_exists($config, 'get')) {
      $pluginId = (string) $config->get('plugin_id');
      if ($pluginId !== '') {
        return $pluginId;
      }
    }
    if (method_exists($config, 'getResourcePlugin')) {
      try {
        return (string) $config->getResourcePlugin()->getPluginId();
      }
      catch (\Throwable $e) {
        // Fall through to the empty return below.
      }
    }
    return '';
  }

  /**
   * Resolves the provider module of a REST resource plugin.
   *
   * @param string $plugin_id
   *   The REST resource plugin id.
   *
   * @return string
   *   The provider module machine name, or ''.
   */
  protected function restProvider(string $plugin_id): string {
    if (!$this->restManager) {
      return '';
    }
    try {
      $definition = $this->restManager->getDefinition($plugin_id, FALSE);
      return (string) ($definition['provider'] ?? '');
    }
    catch (\Throwable $e) {
      return '';
    }
  }

}
