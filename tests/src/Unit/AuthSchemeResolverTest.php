<?php

namespace Drupal\Tests\openapi_explorer\Unit;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\openapi_explorer\OpenApi\AuthSchemeResolver;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * Tests how authentication providers become OpenAPI security schemes.
 *
 * @coversDefaultClass \Drupal\openapi_explorer\OpenApi\AuthSchemeResolver
 * @group openapi_explorer
 */
class AuthSchemeResolverTest extends UnitTestCase {

  /**
   * Builds a resolver for a site with the given modules and JWT header.
   *
   * @param string[] $modules
   *   The modules to report as installed.
   * @param string $jwt_header
   *   The configured JWT header name.
   *
   * @return \Drupal\openapi_explorer\OpenApi\AuthSchemeResolver
   *   The resolver.
   */
  protected function resolver(array $modules, string $jwt_header = 'Authorization'): AuthSchemeResolver {
    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('moduleExists')
      ->willReturnCallback(function ($module) use ($modules) {
        return in_array($module, $modules, TRUE);
      });

    $configFactory = $this->getConfigFactoryStub([
      'openapi_explorer.settings' => ['auth.jwt_header' => $jwt_header],
      'key_auth.settings' => ['param_name' => 'api-key'],
    ]);

    // No routes exist, so every optional token endpoint resolves to ''.
    $routeProvider = $this->createMock(RouteProviderInterface::class);
    $routeProvider->method('getRouteByName')
      ->willThrowException(new RouteNotFoundException());

    return new AuthSchemeResolver(
      $moduleHandler,
      $configFactory,
      $routeProvider,
      new RequestStack()
    );
  }

  /**
   * Tests that JWT is only described when the JWT module is installed.
   *
   * @covers ::schemes
   */
  public function testJwtRequiresTheModule() {
    $this->assertArrayNotHasKey('jwt_auth', $this->resolver([])->schemes([]));
    $this->assertArrayHasKey('jwt_auth', $this->resolver(['jwt'])->schemes([]));
  }

  /**
   * Tests that the standard header is described as an HTTP bearer scheme.
   *
   * @covers ::jwtScheme
   */
  public function testJwtWithStandardHeader() {
    $scheme = $this->resolver(['jwt'])->schemes([])['jwt_auth'];
    $this->assertSame('http', $scheme['type']);
    $this->assertSame('bearer', $scheme['scheme']);
    $this->assertSame('JWT', $scheme['bearerFormat']);
  }

  /**
   * Tests that a custom header is described as the API key header it is.
   *
   * The http/bearer scheme implies the Authorization header, so a site reading
   * another one must be described differently or generated clients would send
   * the token to the wrong place.
   *
   * @covers ::jwtScheme
   */
  public function testJwtWithCustomHeader() {
    $scheme = $this->resolver(['jwt'], 'JWT-Authorization')->schemes([])['jwt_auth'];
    $this->assertSame('apiKey', $scheme['type']);
    $this->assertSame('header', $scheme['in']);
    $this->assertSame('JWT-Authorization', $scheme['name']);
    $this->assertStringContainsString('JWT-Authorization', $scheme['description']);
  }

  /**
   * Tests the JWT header accessor and its fallback.
   *
   * @covers ::jwtHeader
   */
  public function testJwtHeader() {
    $this->assertSame('Authorization', $this->resolver(['jwt'])->jwtHeader());
    $this->assertSame('JWT-Authorization', $this->resolver(['jwt'], 'JWT-Authorization')->jwtHeader());
    // An empty setting falls back to the standard header.
    $this->assertSame('Authorization', $this->resolver(['jwt'], '  ')->jwtHeader());
  }

  /**
   * Tests that the route provider is mapped to the auth provider id.
   *
   * @covers ::schemeName
   */
  public function testSchemeName() {
    $resolver = $this->resolver(['jwt', 'basic_auth']);
    // The JWT module's authentication provider id is jwt_auth, which is what
    // appears in a route's "_auth" option.
    $this->assertSame('jwt_auth', $resolver->schemeName('jwt_auth'));
    $this->assertSame('basic_auth', $resolver->schemeName('basic_auth'));
    $this->assertSame('', $resolver->schemeName('no_such_provider'));
    // Without the module, the provider is not described.
    $this->assertSame('', $this->resolver([])->schemeName('jwt_auth'));
  }

  /**
   * Tests that a missing token endpoint yields an empty URL, not an error.
   *
   * @covers ::jwtTokenUrl
   * @covers ::oauthTokenUrl
   */
  public function testMissingRoutes() {
    $resolver = $this->resolver(['jwt']);
    $this->assertSame('', $resolver->jwtTokenUrl());
    $this->assertSame('', $resolver->oauthTokenUrl());
    // The CSRF endpoint falls back to core's well-known path.
    $this->assertSame('/session/token', $resolver->csrfTokenUrl());
  }

  /**
   * Tests that only the schemes actually in use are returned.
   *
   * @covers ::schemes
   */
  public function testSchemesAreFiltered() {
    $schemes = $this->resolver(['jwt', 'basic_auth'])->schemes(['jwt_auth' => TRUE]);
    $this->assertSame(['jwt_auth'], array_keys($schemes));
  }

}
