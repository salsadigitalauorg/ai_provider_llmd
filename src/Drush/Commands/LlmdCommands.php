<?php

declare(strict_types=1);

namespace Drupal\ai_provider_llmd\Drush\Commands;

use Drupal\ai_provider_llmd\LlmdClient\LlmdClient;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\key\KeyRepositoryInterface;
use Drush\Attributes as CLI;
use Drush\Boot\DrupalBootLevels;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides Drush commands for the LLM-d AI Provider.
 */
class LlmdCommands extends DrushCommands {

  /**
   * Config settings.
   */
  const string CONFIG_NAME = 'ai_provider_llmd.settings';

  /**
   * The constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\ai_provider_llmd\LlmdClient\LlmdClient $llmdClient
   *   The LLM-d client.
   * @param \Drupal\key\KeyRepositoryInterface $keyRepository
   *   The key repository.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected LlmdClient $llmdClient,
    protected KeyRepositoryInterface $keyRepository,
  ) {
    parent::__construct();
  }

  /**
   * Return an instance of these Drush commands.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The container.
   *
   * @return \Drupal\ai_provider_llmd\Drush\Commands\LlmdCommands
   *   The instance of Drush commands.
   */
  public static function create(ContainerInterface $container): LlmdCommands {
    return new LlmdCommands(
      $container->get('config.factory'),
      $container->get('ai_provider_llmd.client'),
      $container->get('key.repository')
    );
  }

  /**
   * Test the connection to the LLM-d orchestrator.
   */
  #[CLI\Command(name: 'ai_provider_llmd:test', aliases: ['llmd:test', 'llmd-test'])]
  #[CLI\Option(name: 'model', description: 'The model to test chat / connection with. Defaults to "mistral-7b".')]
  #[CLI\Usage(name: 'drush ai_provider_llmd:test', description: 'Tests the connection to the LLM-d orchestrator and lists available models.')]
  #[CLI\Bootstrap(level: DrupalBootLevels::FULL)]
  public function testConnection($options = ['model' => 'qwen3-4b']): void {
    $config = $this->configFactory->get(static::CONFIG_NAME);
    $host = $config->get('host');
    $api_key_id = $config->get('api_key');
    $timeout = $config->get('timeout') ?: 30;

    if (empty($host) || empty($api_key_id)) {
      $this->logger()->error(dt('LLM-d configuration is incomplete. Host URL and API key are required.'));
      $this->logger()->error(dt('Please configure the module at /admin/config/ai/providers/llmd'));
      return;
    }

    $key = $this->keyRepository->getKey($api_key_id);
    if (!$key) {
      $this->logger()->error(dt('The configured API key "@key_id" does not exist.', ['@key_id' => $api_key_id]));
      return;
    }

    if (empty($key->getKeyValue())) {
      $this->logger()->error(dt('The configured API key "@key_id" is empty.', ['@key_id' => $api_key_id]));
      return;
    }

    $this->logger()->notice(dt('Testing connection to LLM-d orchestrator...'));
    $this->logger()->notice(dt('Host: @host', ['@host' => $host]));
    $this->logger()->notice(dt('API Key: @key_name', ['@key_name' => $key->label()]));
    $this->logger()->notice(dt('Timeout: @timeout seconds', ['@timeout' => $timeout]));

    try {
      $this->llmdClient->setConfiguration($host, $api_key_id, $timeout, TRUE);

      $this->logger()->notice(dt('Checking health endpoint...'));
      if (!$this->llmdClient->health()) {
        $this->logger()->error(dt('✗ Failed to connect to LLM-d orchestrator. Health check failed.'));
        $this->logger()->error(dt('Please verify your configuration and that the orchestrator is running.'));
        return;
      }

      $this->logger()->success(dt('✓ Successfully connected to LLM-d orchestrator'));

      try {
        $this->logger()->notice(dt('Fetching available models...'));
        $models = $this->llmdClient->getModels();
        $model_count = count($models);
        if ($model_count === 0) {
          $this->logger()->error(dt('No models found on the orchestrator.'));
          return;
        }
        $this->logger()->success(dt('✓ Found @count available models:', ['@count' => $model_count]));
        $rows = [];
        $model_available = FALSE;
        foreach ($models as $model) {
          $model_id = $model['id'] ?? 'N/A';
          if ($model_id === $options['model']) {
            $model_available = TRUE;
          }
          $rows[] = [
            $model_id,
            $model['name'] ?? $model['id'] ?? 'N/A',
            isset($model['context_length']) ? number_format($model['context_length']) : 'N/A',
            $model['owned_by'] ?? 'N/A',
          ];
        }
        $this->io()->table(
          ['Model ID', 'Name', 'Context Length', 'Owned By'],
          $rows
        );

        $this->logger()->notice(dt('Testing chat completion endpoint...'));

        if (!$model_available) {
          $this->logger()->warning(dt('Model @model not available. Skipping chat completion test.', ['@model' => $options['model']]));
        }
        else {
          $this->logger()->notice(dt('Using model: @model', ['@model' => $options['model']]));

          try {
            $payload = [
              'model' => $options['model'],
              'messages' => [
                [
                  'role' => 'user',
                  'content' => 'Can you give me information about Australian industry?',
                ],
              ],
              'stream' => FALSE,
            ];
            $time_start = microtime(TRUE);
            $response = $this->llmdClient->chatCompletion($payload);
            $time_end = microtime(TRUE);
            $time_elapsed = ($time_end - $time_start);
            if ($response) {
              $this->logger()->success(dt('✓ Chat completion endpoint test successful. Took @time_elapsed seconds', ['@time_elapsed' => number_format($time_elapsed, 2)]));

              $this->logger()->notice(dt('Response:' . PHP_EOL . '@response', ['@response' => $response]));
            }
            else {
              $this->logger()->error(dt('✗ Chat completion endpoint test failed: No response received'));
            }
          }
          catch (\Exception $e) {
            $this->logger()->error(dt('✗ Chat completion endpoint test failed: @error', ['@error' => $e->getMessage()]));
          }
        }
      }
      catch (\Exception $e) {
        $this->logger()->warning(dt('Connected to orchestrator but failed to retrieve models.'));
        $this->logger()->error(dt('Error: @error', ['@error' => $e->getMessage()]));
      }
    }
    catch (\Exception $e) {
      $this->logger()->error(dt('✗ Connection test failed: @error', ['@error' => $e->getMessage()]));
      $this->logger()->error(dt('Please check your configuration and network connectivity.'));
    }
  }

}
