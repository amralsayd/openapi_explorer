<?php

namespace Drupal\openapi_explorer\OpenApi;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Resolves OpenAPI security schemes from what the site actually has installed.
 *
 * Routes declare their authentication providers in the "_auth" route option;
 * this service turns those provider names into OpenAPI securityScheme objects,
 * reading the real details (the API key header name, the OAuth 2 token URL, the
 * session cookie name) from the modules that provide them rather than assuming
 * any particular configuration. Providers whose module is not installed are
 * simply omitted.
 */
class AuthSchemeResolver {

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The route provider.
   *
   * @var \Drupal\Core\Routing\RouteProviderInterface
   */
  protected $routeProvider;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * Constructs the resolver.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Routing\RouteProviderInterface $route_provider
   *   The route provider.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   */
  public function __construct(ModuleHandlerInterface $module_handler, ConfigFactoryInterface $config_factory, RouteProviderInterface $route_provider, RequestStack $request_stack) {
    $this->moduleHandler = $module_handler;
    $this->configFactory = $config_factory;
    $this->routeProvider = $route_provider;
    $this->requestStack = $request_stack;
  }

  /**
   * Maps a route authentication provider to a security scheme name.
   *
   * @param string $provider
   *   The authentication provider id from the route's "_auth" option.
   *
   * @return string
   *   The scheme name, or '' when the provider is not one we describe.
   */
  public function schemeName(string $provider): string {
    $provider = strtolower(trim($provider));
    return isset($this->allSchemes()[$provider]) ? $provider : '';
  }

  /**
   * Returns the securityScheme definitions for the schemes actually used.
   *
   * @param array $used
   *   Map of scheme name to TRUE. When empty, every available scheme is
   *   returned.
   *
   * @return array
   *   Map of scheme name to an OpenAPI securityScheme object.
   */
  public function schemes(array $used): array {
    $all = $this->allSchemes();
    if (!$used) {
      return $all;
    }
    return array_intersect_key($all, $used);
  }

  /**
   * Builds every security scheme this site can describe.
   *
   * @return array
   *   Map of scheme name to an OpenAPI securityScheme object.
   */
  protected function allSchemes(): array {
    $schemes = [];

    if ($this->moduleHandler->moduleExists('basic_auth')) {
      $schemes['basic_auth'] = [
        'type' => 'http',
        'scheme' => 'basic',
        'description' => 'HTTP Basic authentication with a Drupal username and password.',
      ];
    }

    if ($this->moduleHandler->moduleExists('key_auth')) {
      $header = $this->keyAuthHeader();
      $schemes['key_auth'] = [
        'type' => 'apiKey',
        'in' => 'header',
        'name' => $header,
        'description' => 'A user API key sent in the ' . $header . ' header.',
      ];
    }

    if ($this->moduleHandler->moduleExists('jwt')) {
      $schemes['jwt_auth'] = $this->jwtScheme();
    }

    $tokenUrl = $this->oauthTokenUrl();
    if ($tokenUrl !== '') {
      $schemes['oauth2'] = [
        'type' => 'oauth2',
        'flows' => [
          'clientCredentials' => [
            'tokenUrl' => $tokenUrl,
            'scopes' => new \stdClass(),
          ],
        ],
      ];
    }

    $schemes['cookie'] = [
      'type' => 'apiKey',
      'in' => 'cookie',
      'name' => $this->sessionCookieName(),
      'description' => 'The Drupal session cookie, as issued after a normal login. Unsafe methods additionally require an X-CSRF-Token header.',
    ];

    return $schemes;
  }

  /**
   * The header name the key_auth module expects the API key in.
   *
   * @return string
   *   The header name.
   */
  public function keyAuthHeader(): string {
    $name = (string) $this->configFactory->get('key_auth.settings')->get('param_name');
    return $name !== '' ? $name : 'api-key';
  }

  /**
   * The header a JSON Web Token is sent in.
   *
   * Defaults to the standard Authorization header. Sites whose JWT provider
   * reads a different header configure it, because that cannot be detected.
   *
   * @return string
   *   The header name.
   */
  public function jwtHeader(): string {
    $name = trim((string) $this->configFactory->get('openapi_explorer.settings')->get('auth.jwt_header'));
    return $name !== '' ? $name : 'Authorization';
  }

  /**
   * The endpoint that issues a JWT for the current session, or ''.
   *
   * Provided by the jwt_auth_issuer submodule of the JWT module; a site may
   * issue tokens some other way, in which case there is nothing to return.
   *
   * @return string
   *   The token path.
   */
  public function jwtTokenUrl(): string {
    return $this->pathForRoute('jwt_auth_issuer.jwt_auth_issuer_controller_generateToken');
  }

  /**
   * Describes the JWT scheme according to the header it actually uses.
   *
   * @return array
   *   An OpenAPI securityScheme object.
   */
  protected function jwtScheme(): array {
    $header = $this->jwtHeader();
    if (strcasecmp($header, 'Authorization') === 0) {
      return [
        'type' => 'http',
        'scheme' => 'bearer',
        'bearerFormat' => 'JWT',
        'description' => 'A JSON Web Token sent as "Authorization: Bearer <token>".',
      ];
    }
    // The http/bearer scheme implies the Authorization header, so a site that
    // reads a different one is described as the API key header it really is.
    // Generated clients then send the token where the site expects it.
    return [
      'type' => 'apiKey',
      'in' => 'header',
      'name' => $header,
      'description' => 'A JSON Web Token sent as "' . $header . ': Bearer <token>".',
    ];
  }

  /**
   * The OAuth 2 token endpoint path, or '' when no provider is installed.
   *
   * @return string
   *   The token path.
   */
  public function oauthTokenUrl(): string {
    return $this->pathForRoute('oauth2_token.token');
  }

  /**
   * The CSRF token endpoint path used by cookie-authenticated requests.
   *
   * @return string
   *   The CSRF token path.
   */
  public function csrfTokenUrl(): string {
    $path = $this->pathForRoute('system.csrftoken');
    return $path !== '' ? $path : '/session/token';
  }

  /**
   * The site's session cookie name.
   *
   * @return string
   *   The cookie name, or a generic description when it cannot be determined.
   */
  public function sessionCookieName(): string {
    try {
      $request = $this->requestStack->getCurrentRequest();
      if ($request && $request->hasSession()) {
        $name = (string) $request->getSession()->getName();
        if ($name !== '') {
          return $name;
        }
      }
    }
    catch (\Throwable $e) {
      // Fall through to the generic name below.
    }
    // Drupal derives the cookie name from the host, e.g. SESS<hash> or
    // SSESS<hash> over HTTPS.
    return 'SESS';
  }

  /**
   * Returns the path of a route, or '' when the route does not exist.
   *
   * @param string $route_name
   *   The route name.
   *
   * @return string
   *   The route path.
   */
  protected function pathForRoute(string $route_name): string {
    try {
      return (string) $this->routeProvider->getRouteByName($route_name)->getPath();
    }
    catch (\Throwable $e) {
      return '';
    }
  }

}
