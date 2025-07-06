<?php

namespace Drupal\ai_provider_llmd\Plugin\AiProvider;

use Drupal\ai\Attribute\AiProvider;
use Drupal\ai\Base\AiProviderClientBase;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatInterface;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai_provider_llmd\LlmdClient\LlmdClient;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Unicode;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the 'LLM-d' AI provider.
 */
#[AiProvider(
  id: 'llmd',
  label: new TranslatableMarkup('LLM-d'),
)]
class LlmdAiProvider extends AiProviderClientBase implements ChatInterface {

  /**
   * The LLM-d client.
   *
   * @var \Drupal\ai_provider_llmd\LlmdClient\LlmdClient
   */
  protected LlmdClient $llmdClient;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $plugin = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $plugin->llmdClient = $container->get('ai_provider_llmd.client');
    return $plugin;
  }

  /**
   * {@inheritdoc}
   */
  public function getConfig(): ImmutableConfig {
    return $this->configFactory->get('ai_provider_llmd.settings');
  }

  /**
   * {@inheritdoc}
   */
  public function getApiDefinition(): array {
    return [
      'chat' => [
        'url' => '/v1/chat/completions',
        'method' => 'POST',
        'stream_url' => '/v1/chat/completions',
        'stream_method' => 'POST',
      ],
      'completions' => [
        'url' => '/v1/completions',
        'method' => 'POST',
        'stream_url' => '/v1/completions',
        'stream_method' => 'POST',
      ],
      'models' => [
        'url' => '/v1/models',
        'method' => 'GET',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getModelSettings(string $model_id, array $generalConfig = []): array {
    // Return default settings for LLM-d models.
    return [
      'temperature' => [
        'type' => 'float',
        'default' => 0.7,
        'min' => 0.0,
        'max' => 2.0,
        'step' => 0.1,
      ],
      'max_tokens' => [
        'type' => 'integer',
        'default' => 1000,
        'min' => 1,
        'max' => 4096,
      ],
      'top_p' => [
        'type' => 'float',
        'default' => 1.0,
        'min' => 0.0,
        'max' => 1.0,
        'step' => 0.01,
      ],
      'frequency_penalty' => [
        'type' => 'float',
        'default' => 0.0,
        'min' => -2.0,
        'max' => 2.0,
        'step' => 0.1,
      ],
      'presence_penalty' => [
        'type' => 'float',
        'default' => 0.0,
        'min' => -2.0,
        'max' => 2.0,
        'step' => 0.1,
      ],
      'stop' => [
        'type' => 'array',
        'default' => [],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getConfiguredModels(?string $operation_type = NULL, array $capabilities = []): array {
    $models = [];
    
    try {
      $this->loadClient();
      $llmd_models = $this->llmdClient->getModels();
      
      foreach ($llmd_models as $model) {
        $model_id = $model['id'];
        
        // Filter by operation type if specified
        if ($operation_type && $operation_type !== 'chat') {
          // Currently only chat is supported
          continue;
        }
        
        // Simple key-value format for dropdown compatibility
        $models[$model_id] = $model_id;
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to load models from LLM-d: @error', ['@error' => $e->getMessage()]);
    }
    
    return $models;
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedOperationTypes(): array {
    return ['chat'];
  }

  /**
   * {@inheritdoc}
   */
  public function chat(ChatInput|array|string $input, string $model_id, array $tags = []): ChatOutput {
    // Use Drupal's validation for model_id
    if (empty($model_id) || !preg_match('/^[a-zA-Z0-9._-]+$/', $model_id) || strlen($model_id) > 100) {
      throw new \InvalidArgumentException('Invalid model ID provided.');
    }
    
    $this->loadClient();
    
    // Convert input to ChatInput object if needed.
    if (is_string($input)) {
      // Use Drupal's text processing
      $input = Html::decodeEntities($input);
      $input = Unicode::truncate($input, 102400, TRUE, TRUE); // 100KB limit
      $input = new ChatInput([
        new ChatMessage('user', $input, '', [])
      ]);
    }
    elseif (is_array($input)) {
      // Convert array to ChatInput with Drupal validation.
      $chat_messages = [];
      foreach ($input as $message) {
        if (is_array($message) && isset($message['role'], $message['content'])) {
          // Use Drupal's built-in validation
          $role = Html::escape(trim($message['role']));
          $content = Html::decodeEntities($message['content']);
          $content = Unicode::truncate($content, 102400, TRUE, TRUE);
          $name = isset($message['name']) ? Html::escape(trim($message['name'])) : '';
          
          // Validate role against allowed values
          $allowed_roles = ['system', 'user', 'assistant', 'function'];
          if (!in_array(strtolower($role), $allowed_roles)) {
            $role = 'user'; // Default to user role
          }
          
          $chat_messages[] = new ChatMessage($role, $content, $name, $message['metadata'] ?? []);
        }
      }
      $input = new ChatInput($chat_messages);
    }
    
    // Convert ChatInput to LLM-d API format.
    $messages = [];
    foreach ($input->getMessages() as $message) {
      $role = $message->getRole();
      $content = $message->getText();
      
      // Skip empty messages
      if (empty($role) || empty(trim($content))) {
        continue;
      }
      
      $messages[] = [
        'role' => $role,
        'content' => $content,
      ];
    }
    
    // Ensure we have at least one valid message
    if (empty($messages)) {
      throw new \InvalidArgumentException('No valid messages provided for chat completion.');
    }

    // Build the payload.
    $payload = [
      'model' => $model_id,
      'messages' => $messages,
      'stream' => FALSE,
    ];

    // Add optional parameters from configuration.
    $config = $input->getConfiguration();
    if (!empty($config)) {
      $allowed_params = ['temperature', 'max_tokens', 'top_p', 'frequency_penalty', 'presence_penalty', 'stop'];
      foreach ($allowed_params as $param) {
        if (isset($config[$param])) {
          $payload[$param] = $config[$param];
        }
      }
    }

    try {
      $response = $this->llmdClient->chatCompletion($payload);
      
      // Parse the response.
      if (isset($response['choices']) && !empty($response['choices'])) {
        $choice = $response['choices'][0];
        $message_content = $choice['message']['content'] ?? '';
        
        // Create response message.
        $response_message = new ChatMessage(
          'assistant',
          $message_content,
          '',
          []
        );
        
        // Create metadata.
        $metadata = [
          'model' => $response['model'] ?? $model_id,
          'usage' => $response['usage'] ?? [],
          'finish_reason' => $choice['finish_reason'] ?? 'stop',
        ];
        
        return new ChatOutput(
          [$response_message],
          $metadata,
          $response
        );
      }
      else {
        throw new \Exception('No response choices returned from LLM-d');
      }
    }
    catch (\Exception $e) {
      $this->logger->error('LLM-d chat completion failed: @error', ['@error' => $e->getMessage()]);
      throw new \Exception('Chat completion failed: ' . $e->getMessage());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function isUsable(?string $operation_type = NULL, array $capabilities = []): bool {
    $config = $this->getConfig();
    $host = $config->get('host');
    $api_key_id = $config->get('api_key');
    
    // Check if basic configuration is present.
    if (empty($host) || empty($api_key_id)) {
      return FALSE;
    }
    
    // Check if the requested operation type is supported.
    if ($operation_type && !in_array($operation_type, $this->getSupportedOperationTypes())) {
      return FALSE;
    }
    
    // Optional: Test connection to verify usability.
    try {
      $this->loadClient();
      return $this->llmdClient->health();
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('ai_provider_llmd')->warning('LLM-d provider not usable: @error', ['@error' => $e->getMessage()]);
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedCapabilities(): array {
    return [
      'chat',
      'completion',
      'streaming',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getMaxInputTokens(string $model_id): int {
    // Default context window for LLM-d models.
    // This could be made configurable or retrieved from the model registry.
    return 4096;
  }

  /**
   * {@inheritdoc}
   */
  public function getMaxOutputTokens(string $model_id): int {
    // Default maximum output tokens for LLM-d models.
    // This could be made configurable or retrieved from the model registry.
    return 2048;
  }

  /**
   * {@inheritdoc}
   */
  public function setAuthentication(mixed $authentication): void {
    // LLM-d uses API key authentication which is handled through
    // the Key module and configuration. This method is implemented
    // for interface compliance but authentication is managed
    // through the configuration system.
    
    if (is_string($authentication)) {
      // If a string is provided, we could potentially update the API key,
      // but for security reasons, we'll log this and recommend using
      // the proper configuration interface.
      $this->logger->info('Authentication update attempted for LLM-d provider. Use configuration interface instead.');
    }
  }

  /**
   * Load and configure the LLM-d client.
   */
  protected function loadClient(): void {
    $config = $this->getConfig();
    $host = $config->get('host');
    $api_key_id = $config->get('api_key');
    $timeout = $config->get('timeout') ?: 30;
    $debug = $config->get('debug') ?: FALSE;
    
    if (empty($host)) {
      throw new \Exception('LLM-d host URL is not configured');
    }
    
    if (empty($api_key_id)) {
      throw new \Exception('LLM-d API key is not configured');
    }
    
    $this->llmdClient->setConfiguration($host, $api_key_id, $timeout, $debug);
  }


}