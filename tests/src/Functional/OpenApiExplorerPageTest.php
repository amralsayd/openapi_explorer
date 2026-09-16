<?php

namespace Drupal\Tests\openapi_explorer\Functional;

use Drupal\Tests\BrowserTestBase;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests the documentation page, the specification routes and the settings form.
 *
 * @group openapi_explorer
 */
class OpenApiExplorerPageTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'block',
    'openapi_explorer',
    'openapi_explorer_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The documentation page path.
   */
  const DOCS_PATH = '/admin/config/services/openapi-explorer';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->config('openapi_explorer.settings')
      ->set('scan.sources', ['custom', 'contrib', 'profile', 'core'])
      ->save();
  }

  /**
   * Tests that the documentation is not readable without the permission.
   */
  public function testAccessIsRestricted() {
    $this->drupalGet(self::DOCS_PATH);
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet(self::DOCS_PATH . '/openapi.json');
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet(self::DOCS_PATH . '/openapi.yaml');
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet(self::DOCS_PATH . '/settings');
    $this->assertSession()->statusCodeEquals(403);

    // Reading the documentation does not grant administering it.
    $this->drupalLogin($this->drupalCreateUser(['access openapi explorer']));
    $this->drupalGet(self::DOCS_PATH);
    $this->assertSession()->statusCodeEquals(200);
    $this->drupalGet(self::DOCS_PATH . '/settings');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Tests the rendered documentation page.
   */
  public function testDocumentationPage() {
    $this->drupalLogin($this->drupalCreateUser(['access openapi explorer']));
    $this->drupalGet(self::DOCS_PATH);
    $this->assertSession()->statusCodeEquals(200);

    $assert = $this->assertSession();
    $assert->elementExists('css', '.oae');
    $assert->pageTextContains('List the available items.');
    $assert->responseContains('/api/test/items');
    // Internal operations are never published.
    $assert->responseNotContains('This should never be published.');

    // Assets are attached as a library, never inlined into the markup.
    $assert->responseContains('openapi-explorer.css');
    $assert->responseContains('openapi-explorer.js');
    $assert->elementNotExists('css', '.oae style');
    $assert->elementNotExists('css', '.oae script');

    // The interactive tester is present by default.
    $assert->elementExists('css', '.oae-try-send');

    // Turning the tester off removes it from the page.
    $this->config('openapi_explorer.settings')->set('tester.enabled', FALSE)->save();
    $this->drupalGet(self::DOCS_PATH);
    $assert->elementNotExists('css', '.oae-try-send');
    $assert->elementNotExists('css', '.oae-creds');
  }

  /**
   * Tests the JSON and YAML specification routes.
   */
  public function testSpecificationRoutes() {
    $this->drupalLogin($this->drupalCreateUser(['access openapi explorer']));

    $this->drupalGet(self::DOCS_PATH . '/openapi.json');
    $this->assertSession()->statusCodeEquals(200);
    $spec = json_decode($this->getSession()->getPage()->getContent(), TRUE);
    $this->assertIsArray($spec);
    $this->assertSame('3.0.3', $spec['openapi']);
    $this->assertNotEmpty($spec['info']['title']);
    $this->assertNotEmpty($spec['info']['version']);
    $this->assertArrayHasKey('/api/test/items', $spec['paths']);
    $this->assertArrayNotHasKey('/api/test/internal', $spec['paths']);
    $this->assertArrayHasKey('ErrorResponse', $spec['components']['schemas']);

    // Every operation carries the keys the specification requires.
    foreach ($spec['paths'] as $path => $operations) {
      foreach ($operations as $verb => $operation) {
        $this->assertNotEmpty($operation['responses'], "$verb $path has responses");
        $this->assertNotEmpty($operation['operationId'], "$verb $path has an operationId");
      }
    }

    $this->drupalGet(self::DOCS_PATH . '/openapi.yaml');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderContains('Content-Type', 'application/yaml');
    $yaml = Yaml::parse($this->getSession()->getPage()->getContent());
    $this->assertSame($spec['openapi'], $yaml['openapi']);
    $this->assertSame(array_keys($spec['paths']), array_keys($yaml['paths']));
  }

  /**
   * Tests that the settings form saves and changes what is documented.
   */
  public function testSettingsForm() {
    $this->drupalLogin($this->drupalCreateUser([
      'access openapi explorer',
      'administer openapi explorer',
    ]));

    $this->drupalGet(self::DOCS_PATH . '/settings');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->fieldExists('scan[path_prefixes]');
    $this->assertSession()->fieldExists('annotations[tag_prefix]');

    // Narrow the prefixes so the fixture routes drop out of the documentation.
    $this->submitForm([
      'scan[path_prefixes]' => '/nothing-here',
      'info[title]' => 'My Test API',
    ], 'Save configuration');
    $this->assertSession()->statusCodeEquals(200);

    $config = $this->config('openapi_explorer.settings');
    $this->assertSame(['/nothing-here'], $config->get('scan.path_prefixes'));
    $this->assertSame('My Test API', $config->get('info.title'));

    $this->drupalGet(self::DOCS_PATH);
    $this->assertSession()->responseNotContains('/api/test/items');

    $this->drupalGet(self::DOCS_PATH . '/openapi.json');
    $spec = json_decode($this->getSession()->getPage()->getContent(), TRUE);
    $this->assertSame('My Test API', $spec['info']['title']);
  }

  /**
   * Tests that the settings form rejects an invalid tag prefix.
   */
  public function testSettingsFormValidation() {
    $this->drupalLogin($this->drupalCreateUser([
      'access openapi explorer',
      'administer openapi explorer',
    ]));

    $this->drupalGet(self::DOCS_PATH . '/settings');
    $this->submitForm(['annotations[tag_prefix]' => '9 bad prefix!'], 'Save configuration');
    $this->assertSession()->pageTextContains('The tag prefix must start with a letter');
    $this->assertSame('oapi', $this->config('openapi_explorer.settings')->get('annotations.tag_prefix'));
  }

  /**
   * Tests that changing the tag prefix changes which docblocks are read.
   */
  public function testTagPrefixAffectsParsing() {
    $this->drupalLogin($this->drupalCreateUser([
      'access openapi explorer',
      'administer openapi explorer',
    ]));

    $this->drupalGet(self::DOCS_PATH);
    $this->assertSession()->pageTextContains('List the available items.');

    $this->config('openapi_explorer.settings')
      ->set('annotations.tag_prefix', 'somethingelse')
      ->save();
    $this->drupalGet(self::DOCS_PATH);
    // The fixtures use the default prefix, so their summaries disappear.
    $this->assertSession()->pageTextNotContains('List the available items.');
    // The routes themselves are still discovered.
    $this->assertSession()->responseContains('/api/test/items');
  }

  /**
   * Tests the JWT credentials block and how the scheme is described.
   */
  public function testJwtAuthentication() {
    $this->drupalLogin($this->drupalCreateUser([
      'access openapi explorer',
      'administer openapi explorer',
    ]));

    $this->drupalGet(self::DOCS_PATH);
    $assert = $this->assertSession();
    // The credentials block offers JWT beside the other schemes.
    $assert->elementExists('css', '#oae-jwt-header');
    $assert->elementExists('css', '#oae-jwt-token');
    $assert->elementExists('css', '#oae-jwt-url');
    $assert->elementExists('css', '#oae-jwt-fetch');
    // All three ways of obtaining a token are offered.
    $assert->elementExists('css', '#oae-jwt-mode option[value="token"]');
    $assert->elementExists('css', '#oae-jwt-mode option[value="url"]');
    $assert->elementExists('css', '#oae-jwt-mode option[value="sign"]');
    $assert->elementExists('css', '#oae-jwt-alg option[value="RS256"]');
    $assert->elementExists('css', '#oae-jwt-alg option[value="HS256"]');
    $assert->elementExists('css', '#oae-jwt-claims');
    $assert->elementExists('css', '#oae-jwt-key');
    $assert->elementExists('css', '#oae-jwt-sign');
    // The signing key must never be posted anywhere.
    $assert->elementNotExists('css', '.oae-creds form');

    // The schemes are presented as tabs, one panel per scheme.
    $assert->elementExists('css', '.oae-creds [role="tablist"]');
    $this->assertCount(5, $this->getSession()->getPage()->findAll('css', '.oae-creds [role="tab"]'));
    $this->assertCount(5, $this->getSession()->getPage()->findAll('css', '.oae-creds [role="tabpanel"]'));
    // Exactly one tab starts selected, and each tab labels its own panel.
    $this->assertCount(1, $this->getSession()->getPage()->findAll('css', '.oae-creds [role="tab"][aria-selected="true"]'));
    foreach (['key', 'basic', 'jwt', 'oauth', 'bearer'] as $scheme) {
      $assert->elementExists('css', '#oae-tab-' . $scheme . '[aria-controls="oae-panel-' . $scheme . '"]');
      $assert->elementExists('css', '#oae-panel-' . $scheme . '[aria-labelledby="oae-tab-' . $scheme . '"]');
    }
    // Without JavaScript every panel stays reachable, so none is hidden in the
    // markup the server sends.
    $assert->elementNotExists('css', '.oae-panel[hidden]');
    // And every operation can select it.
    $assert->elementExists('css', '.oae-try-auth option[value="jwt_auth"]');
    // The header field is prefilled with the configured default.
    $this->assertSame(
      'Authorization',
      $this->getSession()->getPage()->findById('oae-jwt-header')->getValue()
    );

    // The tester is configured through drupalSettings, not inline script.
    $assert->responseContains('"jwtHeader":"Authorization"');

    // A custom header is carried through to the page.
    $this->config('openapi_explorer.settings')
      ->set('auth.jwt_header', 'JWT-Authorization')
      ->save();
    $this->drupalGet(self::DOCS_PATH);
    $this->assertSame(
      'JWT-Authorization',
      $this->getSession()->getPage()->findById('oae-jwt-header')->getValue()
    );
    $assert->responseContains('"jwtHeader":"JWT-Authorization"');

    // Turning the tester off removes the JWT block with the rest of it.
    $this->config('openapi_explorer.settings')->set('tester.enabled', FALSE)->save();
    $this->drupalGet(self::DOCS_PATH);
    $assert->elementNotExists('css', '#oae-jwt-token');
  }

  /**
   * Tests that the settings form saves and validates the JWT header.
   */
  public function testJwtHeaderSetting() {
    $this->drupalLogin($this->drupalCreateUser([
      'access openapi explorer',
      'administer openapi explorer',
    ]));

    $this->drupalGet(self::DOCS_PATH . '/settings');
    $this->assertSession()->fieldExists('auth[jwt_header]');

    $this->submitForm(['auth[jwt_header]' => 'JWT-Authorization'], 'Save configuration');
    $this->assertSame(
      'JWT-Authorization',
      $this->config('openapi_explorer.settings')->get('auth.jwt_header')
    );

    // A header name with characters HTTP does not allow is rejected.
    $this->drupalGet(self::DOCS_PATH . '/settings');
    $this->submitForm(['auth[jwt_header]' => 'not a header'], 'Save configuration');
    $this->assertSession()->pageTextContains('not a valid HTTP header name');
    $this->assertSame(
      'JWT-Authorization',
      $this->config('openapi_explorer.settings')->get('auth.jwt_header')
    );

    // Emptying it falls back to the standard header.
    $this->drupalGet(self::DOCS_PATH . '/settings');
    $this->submitForm(['auth[jwt_header]' => ''], 'Save configuration');
    $this->assertSame(
      'Authorization',
      $this->config('openapi_explorer.settings')->get('auth.jwt_header')
    );
  }

  /**
   * Tests that the local tasks are present on the documentation page.
   */
  public function testLocalTasks() {
    // Local tasks only render where the block has been placed.
    $this->drupalPlaceBlock('local_tasks_block');
    $this->drupalLogin($this->drupalCreateUser([
      'access openapi explorer',
      'administer openapi explorer',
    ]));
    $this->drupalGet(self::DOCS_PATH);
    $this->assertSession()->linkExists('Documentation');
    $this->assertSession()->linkExists('Settings');
  }

}
