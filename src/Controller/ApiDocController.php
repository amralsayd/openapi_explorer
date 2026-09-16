<?php

namespace Drupal\openapi_explorer\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheableResponse;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Drupal\openapi_explorer\OpenApi\AuthSchemeResolver;
use Drupal\openapi_explorer\OpenApi\OpenApiBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Serves the API documentation page and the raw OpenAPI specifications.
 */
class ApiDocController extends ControllerBase {

  /**
   * The cache tag invalidated whenever the generated documentation changes.
   */
  const CACHE_TAG = 'openapi_explorer:spec';

  /**
   * The OpenAPI builder.
   *
   * @var \Drupal\openapi_explorer\OpenApi\OpenApiBuilder
   */
  protected $builder;

  /**
   * The security scheme resolver.
   *
   * @var \Drupal\openapi_explorer\OpenApi\AuthSchemeResolver
   */
  protected $authSchemes;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The date formatter.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * Constructs the controller.
   *
   * @param \Drupal\openapi_explorer\OpenApi\OpenApiBuilder $builder
   *   The OpenAPI builder.
   * @param \Drupal\openapi_explorer\OpenApi\AuthSchemeResolver $auth_schemes
   *   The security scheme resolver.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The date formatter.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   */
  public function __construct(OpenApiBuilder $builder, AuthSchemeResolver $auth_schemes, RequestStack $request_stack, DateFormatterInterface $date_formatter, TimeInterface $time) {
    $this->builder = $builder;
    $this->authSchemes = $auth_schemes;
    $this->requestStack = $request_stack;
    $this->dateFormatter = $date_formatter;
    $this->time = $time;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('openapi_explorer.builder'),
      $container->get('openapi_explorer.auth_schemes'),
      $container->get('request_stack'),
      $container->get('date.formatter'),
      $container->get('datetime.time')
    );
  }

  /**
   * Renders the documentation page.
   *
   * @return array
   *   A render array.
   */
  public function page(): array {
    $request = $this->requestStack->getCurrentRequest();
    $basePath = $request ? $request->getBasePath() : '';
    $settings = $this->config('openapi_explorer.settings');
    $parser = $this->builder->parser();

    $build = [
      '#theme' => 'openapi_explorer_page',
      '#categories' => $this->builder->endpointsByCategory(),
      '#coverage' => $this->builder->coverageSummary(),
      '#warnings' => $this->builder->warnings(),
      '#tag_prefix' => $parser->tagPrefix(),
      '#generated' => $this->dateFormatter->format($this->time->getRequestTime(), 'custom', 'Y-m-d H:i:s T'),
      '#spec_json_url' => Url::fromRoute('openapi_explorer.openapi_json')->toString(),
      '#spec_yaml_url' => Url::fromRoute('openapi_explorer.openapi_yaml')->toString(),
      '#base_path' => $basePath,
      '#auth_key_header' => $this->authSchemes->keyAuthHeader(),
      '#auth_oauth_token_url' => $this->authSchemes->oauthTokenUrl(),
      '#auth_jwt_header' => $this->authSchemes->jwtHeader(),
      '#auth_jwt_token_url' => $this->authSchemes->jwtTokenUrl(),
      '#auth_jwt_claims' => $this->defaultJwtClaims(),
      '#tester_enabled' => (bool) $settings->get('tester.enabled'),
      '#attached' => [
        'library' => ['openapi_explorer/explorer'],
        'drupalSettings' => [
          'openapiExplorer' => [
            'basePath' => $basePath,
            'specJsonUrl' => Url::fromRoute('openapi_explorer.openapi_json')->toString(),
            'specYamlUrl' => Url::fromRoute('openapi_explorer.openapi_yaml')->toString(),
            'auth' => [
              'keyHeader' => $this->authSchemes->keyAuthHeader(),
              'oauthTokenUrl' => $this->authSchemes->oauthTokenUrl(),
              'jwtHeader' => $this->authSchemes->jwtHeader(),
              'jwtTokenUrl' => $this->authSchemes->jwtTokenUrl(),
              'csrfTokenUrl' => $this->authSchemes->csrfTokenUrl(),
            ],
          ],
        ],
      ],
    ];
    $this->cacheability()->applyTo($build);
    return $build;
  }

  /**
   * Returns the OpenAPI specification as JSON.
   *
   * @return \Drupal\Core\Cache\CacheableJsonResponse
   *   The JSON response.
   */
  public function openApiJson(): CacheableJsonResponse {
    $response = new CacheableJsonResponse($this->builder->spec());
    $response->addCacheableDependency($this->cacheability());
    return $response;
  }

  /**
   * Returns the OpenAPI specification as YAML.
   *
   * @return \Drupal\Core\Cache\CacheableResponse
   *   The YAML response.
   */
  public function openApiYaml(): CacheableResponse {
    $response = new CacheableResponse($this->builder->toYaml(), 200, [
      'Content-Type' => 'application/yaml; charset=utf-8',
    ]);
    $response->addCacheableDependency($this->cacheability());
    return $response;
  }

  /**
   * A starting claim set for signing a token in the browser.
   *
   * The JWT module's consumer identifies the account from a "drupal.uid"
   * claim, so that is a useful starting point when it is installed. Otherwise
   * there is nothing to assume and the caller fills the claims in.
   *
   * @return string
   *   Pretty-printed JSON.
   */
  protected function defaultJwtClaims(): string {
    $claims = $this->moduleHandler()->moduleExists('jwt')
      ? ['drupal' => ['uid' => (int) $this->currentUser()->id()]]
      : new \stdClass();
    $json = json_encode($claims, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    return $json === FALSE ? '{}' : $json;
  }

  /**
   * The cacheability of the generated documentation.
   *
   * The documentation depends on the route collection, on this module's
   * settings, and on the docblocks in the code itself. Code changes are not
   * observable through a cache tag, so a deployment should follow the usual
   * cache rebuild.
   *
   * @return \Drupal\Core\Cache\CacheableMetadata
   *   The cacheable metadata.
   */
  protected function cacheability(): CacheableMetadata {
    $cacheability = new CacheableMetadata();
    $cacheability->addCacheTags([
      self::CACHE_TAG,
      'config:openapi_explorer.settings',
      'config:system.site',
    ]);
    // The starting claim set names the current account, so the rendered page
    // is per-user rather than per-permission-set.
    $cacheability->addCacheContexts(['user', 'url.site']);
    return $cacheability;
  }

}
