<?php

namespace Drupal\openapi_explorer\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\openapi_explorer\Controller\ApiDocController;
use Drupal\openapi_explorer\OpenApi\DocAnnotationParser;
use Drupal\openapi_explorer\OpenApi\EndpointDiscovery;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configures which routes are documented and how the specification is labelled.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * The endpoint discovery service.
   *
   * @var \Drupal\openapi_explorer\OpenApi\EndpointDiscovery
   */
  protected $discovery;

  /**
   * The cache tags invalidator.
   *
   * @var \Drupal\Core\Cache\CacheTagsInvalidatorInterface
   */
  protected $cacheTagsInvalidator;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    // The parent constructor signature changed across the supported core
    // versions, so extra services are set here rather than by overriding it.
    $instance = parent::create($container);
    $instance->discovery = $container->get('openapi_explorer.discovery');
    $instance->cacheTagsInvalidator = $container->get('cache_tags.invalidator');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'openapi_explorer_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['openapi_explorer.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('openapi_explorer.settings');
    $selected = array_filter((array) $config->get('scan.modules'));

    $form['scan'] = [
      '#type' => 'details',
      '#title' => $this->t('What to document'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];
    $form['scan']['mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Which modules to scan'),
      '#default_value' => $selected ? 'modules' : 'sources',
      '#options' => [
        'sources' => $this->t('Every module from the selected sources'),
        'modules' => $this->t('Only the modules I pick below'),
      ],
    ];
    $form['scan']['sources'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Extension sources'),
      '#options' => [
        'custom' => $this->t('Custom modules'),
        'contrib' => $this->t('Contributed modules'),
        'profile' => $this->t('Modules shipped with the install profile'),
        'core' => $this->t('Drupal core modules'),
      ],
      '#default_value' => array_values(array_filter((array) $config->get('scan.sources'))),
      '#description' => $this->t('Tick Drupal core to document every API the site exposes, including the ones core and contributed modules provide.'),
      '#states' => [
        'visible' => [':input[name="scan[mode]"]' => ['value' => 'sources']],
      ],
    ];

    $form['scan']['modules'] = [
      '#type' => 'container',
      '#states' => [
        'visible' => [':input[name="scan[mode]"]' => ['value' => 'modules']],
      ],
    ];
    $labels = [
      'custom' => $this->t('Custom modules'),
      'contrib' => $this->t('Contributed modules'),
      'profile' => $this->t('Profile modules'),
      'core' => $this->t('Core modules'),
    ];
    foreach ($this->discovery->modulesBySource() as $source => $modules) {
      if (!$modules) {
        continue;
      }
      $checkedHere = array_intersect($selected, array_keys($modules));
      $form['scan']['modules'][$source] = [
        '#type' => 'details',
        '#title' => $labels[$source] ?? $source,
        '#open' => (bool) $checkedHere,
        'list' => [
          '#type' => 'checkboxes',
          '#title' => $labels[$source] ?? $source,
          '#title_display' => 'invisible',
          '#options' => $modules,
          '#default_value' => array_values($checkedHere),
        ],
      ];
    }

    $form['scan']['path_prefixes'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Path prefixes'),
      '#rows' => 3,
      '#default_value' => implode("\n", (array) $config->get('scan.path_prefixes')),
      '#description' => $this->t('One path prefix per line, for example <code>/api</code>. Only routes under one of these prefixes are documented. Leave this empty to document every route of the selected modules.'),
    ];
    $form['scan']['include_rest_resources'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Include REST resources'),
      '#default_value' => (bool) $config->get('scan.include_rest_resources'),
      '#description' => $this->t('Document routes provided by REST resource plugins. Requires the core REST module.'),
    ];
    $form['scan']['include_admin_routes'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Include administrative routes'),
      '#default_value' => (bool) $config->get('scan.include_admin_routes'),
      '#description' => $this->t('Administrative pages are rarely part of an API and are skipped by default.'),
    ];
    $form['scan']['max_endpoints'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum number of endpoints'),
      '#min' => 0,
      '#default_value' => (int) $config->get('scan.max_endpoints'),
      '#description' => $this->t('Discovery stops once this many endpoints have been found, which keeps the page responsive when scanning a whole site. Set to 0 for no limit.'),
    ];

    $form['annotations'] = [
      '#type' => 'details',
      '#title' => $this->t('Annotations'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];
    $form['annotations']['tag_prefix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Docblock tag prefix'),
      '#default_value' => (string) $config->get('annotations.tag_prefix'),
      '#size' => 20,
      '#required' => TRUE,
      '#description' => $this->t('Tags are read as <code>@@@prefix</code> followed by the tag name, for example <code>@@@prefixSummary</code>. Change this if your code is already annotated with another prefix.', [
        '@prefix' => (string) $config->get('annotations.tag_prefix'),
      ]),
    ];

    $form['auth'] = [
      '#type' => 'details',
      '#title' => $this->t('Authentication'),
      '#tree' => TRUE,
    ];
    $form['auth']['jwt_header'] = [
      '#type' => 'textfield',
      '#title' => $this->t('JWT header name'),
      '#default_value' => (string) $config->get('auth.jwt_header'),
      '#size' => 30,
      '#description' => $this->t('The header a JSON Web Token is sent in, used by the interactive tester and written into the specification. Leave it as <code>Authorization</code> unless your site reads a different header; the JWT module also accepts <code>JWT-Authorization</code>.'),
    ];

    $form['info'] = [
      '#type' => 'details',
      '#title' => $this->t('Specification metadata'),
      '#tree' => TRUE,
    ];
    $form['info']['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Title'),
      '#default_value' => (string) $config->get('info.title'),
      '#description' => $this->t('Leave empty to use the site name.'),
    ];
    $form['info']['version'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Version'),
      '#default_value' => (string) $config->get('info.version'),
      '#size' => 20,
    ];
    $form['info']['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#rows' => 3,
      '#default_value' => (string) $config->get('info.description'),
    ];
    $form['info']['servers'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Servers'),
      '#rows' => 3,
      '#default_value' => implode("\n", (array) $config->get('servers')),
      '#description' => $this->t('One base URL per line, added to the specification so generated clients know where to send requests.'),
    ];

    $form['tester'] = [
      '#type' => 'details',
      '#title' => $this->t('Interactive tester'),
      '#tree' => TRUE,
    ];
    $form['tester']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow sending requests from the documentation page'),
      '#default_value' => (bool) $config->get('tester.enabled'),
      '#description' => $this->t('When enabled, the documentation page can issue real requests against this site using credentials entered in the browser.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    $prefix = trim(ltrim((string) $form_state->getValue(['annotations', 'tag_prefix']), '@'));
    if ($prefix === '' || !preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $prefix)) {
      $form_state->setErrorByName('annotations][tag_prefix', $this->t('The tag prefix must start with a letter and contain only letters, numbers and underscores.'));
    }

    $header = trim((string) $form_state->getValue(['auth', 'jwt_header']));
    if ($header !== '' && !preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $header)) {
      $form_state->setErrorByName('auth][jwt_header', $this->t('The JWT header name is not a valid HTTP header name.'));
    }

    foreach ($this->lines((string) $form_state->getValue(['scan', 'path_prefixes'])) as $line) {
      if (strpos($line, '/') !== 0 && strpos($line, ' ') !== FALSE) {
        $form_state->setErrorByName('scan][path_prefixes', $this->t('"@prefix" is not a valid path prefix.', ['@prefix' => $line]));
      }
    }

    if ($form_state->getValue(['scan', 'mode']) === 'sources') {
      $sources = array_filter((array) $form_state->getValue(['scan', 'sources']));
      if (!$sources) {
        $form_state->setErrorByName('scan][sources', $this->t('Select at least one extension source.'));
      }
    }
    elseif (!$this->selectedModules($form_state)) {
      $form_state->setErrorByName('scan][modules', $this->t('Select at least one module to scan.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $mode = $form_state->getValue(['scan', 'mode']);
    $prefix = trim(ltrim((string) $form_state->getValue(['annotations', 'tag_prefix']), '@'));

    $this->config('openapi_explorer.settings')
      ->set('scan.sources', $mode === 'sources'
        ? array_values(array_filter((array) $form_state->getValue(['scan', 'sources'])))
        : (array) $this->config('openapi_explorer.settings')->get('scan.sources'))
      ->set('scan.modules', $mode === 'modules' ? $this->selectedModules($form_state) : [])
      ->set('scan.path_prefixes', $this->lines((string) $form_state->getValue(['scan', 'path_prefixes'])))
      ->set('scan.include_rest_resources', (bool) $form_state->getValue(['scan', 'include_rest_resources']))
      ->set('scan.include_admin_routes', (bool) $form_state->getValue(['scan', 'include_admin_routes']))
      ->set('scan.max_endpoints', (int) $form_state->getValue(['scan', 'max_endpoints']))
      ->set('annotations.tag_prefix', $prefix !== '' ? $prefix : DocAnnotationParser::DEFAULT_TAG_PREFIX)
      ->set('auth.jwt_header', trim((string) $form_state->getValue(['auth', 'jwt_header'])) ?: 'Authorization')
      ->set('info.title', trim((string) $form_state->getValue(['info', 'title'])))
      ->set('info.version', trim((string) $form_state->getValue(['info', 'version'])))
      ->set('info.description', trim((string) $form_state->getValue(['info', 'description'])))
      ->set('servers', $this->lines((string) $form_state->getValue(['info', 'servers'])))
      ->set('tester.enabled', (bool) $form_state->getValue(['tester', 'enabled']))
      ->save();

    $this->cacheTagsInvalidator->invalidateTags([ApiDocController::CACHE_TAG]);
    parent::submitForm($form, $form_state);
  }

  /**
   * Collects the modules ticked across every source group.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return string[]
   *   The selected module machine names.
   */
  protected function selectedModules(FormStateInterface $form_state): array {
    $selected = [];
    foreach (EndpointDiscovery::SOURCES as $source) {
      $values = $form_state->getValue(['scan', 'modules', $source, 'list']);
      if (is_array($values)) {
        $selected = array_merge($selected, array_values(array_filter($values)));
      }
    }
    sort($selected);
    return $selected;
  }

  /**
   * Splits a textarea value into trimmed, non-empty lines.
   *
   * @param string $value
   *   The raw textarea value.
   *
   * @return string[]
   *   The lines.
   */
  protected function lines(string $value): array {
    $lines = preg_split('/\r\n|\r|\n/', $value) ?: [];
    $out = [];
    foreach ($lines as $line) {
      $line = trim($line);
      if ($line !== '') {
        $out[] = $line;
      }
    }
    return $out;
  }

}
