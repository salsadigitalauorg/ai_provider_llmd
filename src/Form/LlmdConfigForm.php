<?php

namespace Drupal\ai_provider_llmd\Form;

use Drupal\ai_provider_llmd\LlmdClient\LlmdClient;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\key\KeyRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configuration form for LLM-d AI Provider.
 */
class LlmdConfigForm extends ConfigFormBase {

  /**
   * Config settings.
   */
  const CONFIG_NAME = 'ai_provider_llmd.settings';

  /**
   * The LLM-d client.
   *
   * @var \Drupal\ai_provider_llmd\LlmdClient\LlmdClient
   */
  protected LlmdClient $llmdClient;

  /**
   * The key repository.
   *
   * @var \Drupal\key\KeyRepositoryInterface
   */
  protected KeyRepositoryInterface $keyRepository;

  /**
   * Constructs a new LlmdConfigForm.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typed_config_manager
   *   The typed config manager.
   * @param \Drupal\ai_provider_llmd\LlmdClient\LlmdClient $llmd_client
   *   The LLM-d client.
   * @param \Drupal\key\KeyRepositoryInterface $key_repository
   *   The key repository.
   */
  public function __construct(ConfigFactoryInterface $config_factory, TypedConfigManagerInterface $typed_config_manager, LlmdClient $llmd_client, KeyRepositoryInterface $key_repository) {
    parent::__construct($config_factory, $typed_config_manager);
    $this->llmdClient = $llmd_client;
    $this->keyRepository = $key_repository;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('ai_provider_llmd.client'),
      $container->get('key.repository')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'llmd_ai_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [static::CONFIG_NAME];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config(static::CONFIG_NAME);

    $form['connection'] = [
      '#type' => 'details',
      '#title' => $this->t('Connection Settings'),
      '#open' => TRUE,
    ];

    $form['connection']['host'] = [
      '#type' => 'url',
      '#title' => $this->t('LLM-d Orchestrator URL'),
      '#description' => $this->t('The base URL for your LLM-d orchestrator instance. Use http://host.docker.internal:8000 for DDEV/Docker environments, or http://localhost:8000 for local development.'),
      '#default_value' => $config->get('host'),
      '#required' => TRUE,
    ];

    // Get available keys for the select list.
    $key_options = $this->getKeyOptions();
    
    $form['connection']['api_key'] = [
      '#type' => 'select',
      '#title' => $this->t('API Key'),
      '#description' => $this->t('Select the API key to use for authentication. Create keys in the <a href="@url">Key management</a> section.', [
        '@url' => '/admin/config/system/keys',
      ]),
      '#options' => $key_options,
      '#default_value' => $config->get('api_key'),
      '#empty_option' => $this->t('- Select a key -'),
      '#required' => TRUE,
    ];

    $form['connection']['timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Request Timeout'),
      '#description' => $this->t('Timeout in seconds for API requests.'),
      '#default_value' => $config->get('timeout') ?: 30,
      '#min' => 1,
      '#max' => 300,
      '#required' => TRUE,
    ];

    $form['debug'] = [
      '#type' => 'details',
      '#title' => $this->t('Debug Settings'),
      '#open' => FALSE,
    ];

    $form['debug']['debug'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable debug logging'),
      '#description' => $this->t('Log detailed information about API requests for troubleshooting.'),
      '#default_value' => $config->get('debug'),
    ];

    // Test connection section.
    $form['test'] = [
      '#type' => 'details',
      '#title' => $this->t('Test Connection'),
      '#open' => FALSE,
    ];

    $form['test']['test_connection'] = [
      '#type' => 'submit',
      '#value' => $this->t('Test Connection'),
      '#submit' => ['::testConnection'],
      '#limit_validation_errors' => [['host'], ['api_key'], ['timeout']],
      '#attributes' => ['class' => ['button', 'button--primary']],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * Test the connection to the LLM-d orchestrator.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function testConnection(array &$form, FormStateInterface $form_state): void {
    $host = $form_state->getValue('host');
    $api_key_id = $form_state->getValue('api_key');
    $timeout = $form_state->getValue('timeout');

    if (empty($host) || empty($api_key_id)) {
      $this->messenger()->addError($this->t('Host URL and API key are required for testing.'));
      return;
    }

    // Additional security check for connection testing
    if (!$this->currentUser()->hasPermission('administer ai providers')) {
      $this->messenger()->addError($this->t('Insufficient permissions to test connection.'));
      return;
    }

    // Log security event
    \Drupal::logger('ai_provider_llmd')->info('Connection test attempted by user @uid for host @host', [
      '@uid' => $this->currentUser()->id(),
      '@host' => $host,
    ]);

    try {
      // Configure the client with form values.
      $this->llmdClient->setConfiguration($host, $api_key_id, $timeout, TRUE);
      
      // Test health endpoint.
      if ($this->llmdClient->health()) {
        $this->messenger()->addStatus($this->t('Successfully connected to LLM-d orchestrator.'));
        
        // Try to get models to verify full functionality.
        try {
          $models = $this->llmdClient->getModels();
          $model_count = count($models);
          $this->messenger()->addStatus($this->t('Found @count available models.', ['@count' => $model_count]));
          
          if ($model_count > 0) {
            $model_names = array_column($models, 'id');
            $this->messenger()->addStatus($this->t('Available models: @models', [
              '@models' => implode(', ', array_slice($model_names, 0, 5)) . ($model_count > 5 ? '...' : '')
            ]));
          }
          
          // Log successful connection
          \Drupal::logger('ai_provider_llmd')->info('Successful connection test to @host with @count models', [
            '@host' => $host,
            '@count' => $model_count,
          ]);
        }
        catch (\Exception $e) {
          $this->messenger()->addWarning($this->t('Connected to orchestrator but failed to retrieve models.'));
          \Drupal::logger('ai_provider_llmd')->warning('Connection test: Health OK but model retrieval failed for @host', [
            '@host' => $host,
          ]);
        }
      }
      else {
        $this->messenger()->addError($this->t('Failed to connect to LLM-d orchestrator. Health check failed.'));
        \Drupal::logger('ai_provider_llmd')->warning('Connection test failed: Health check failed for @host', [
          '@host' => $host,
        ]);
      }
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Connection test failed.'));
      \Drupal::logger('ai_provider_llmd')->error('Connection test failed for @host: @error', [
        '@host' => $host,
        '@error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $host = $form_state->getValue('host');
    if (!empty($host)) {
      // Use Drupal's URL validation
      try {
        $url = Url::fromUri($host);
        // Additional validation for external URLs
        if (!$url->isExternal()) {
          $form_state->setErrorByName('host', $this->t('Please enter an external URL.'));
        }
      }
      catch (\InvalidArgumentException $e) {
        $form_state->setErrorByName('host', $this->t('Please enter a valid URL.'));
      }
    }

    $api_key_id = $form_state->getValue('api_key');
    if (!empty($api_key_id)) {
      // Validate that the key exists using Drupal's key management
      $key = $this->keyRepository->getKey($api_key_id);
      if (!$key) {
        $form_state->setErrorByName('api_key', $this->t('The selected API key does not exist.'));
      }
      elseif (empty($key->getKeyValue())) {
        $form_state->setErrorByName('api_key', $this->t('The selected API key is empty.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config(static::CONFIG_NAME)
      ->set('host', $form_state->getValue('host'))
      ->set('api_key', $form_state->getValue('api_key'))
      ->set('timeout', $form_state->getValue('timeout'))
      ->set('debug', $form_state->getValue('debug'))
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Get available keys for the select options.
   *
   * @return array
   *   Array of key options.
   */
  protected function getKeyOptions(): array {
    $options = [];
    $keys = $this->keyRepository->getKeys();
    
    foreach ($keys as $key) {
      $options[$key->id()] = $key->label();
    }
    
    return $options;
  }

}