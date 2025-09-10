<?php

declare(strict_types=1);

namespace Drupal\ai_provider_llmd\LlmdClient;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;

/**
 * LLM-d API client for distributed inference.
 */
class LlmdClient {

  /**
   * The base URL for the LLM-d orchestrator.
   *
   * @var string
   */
  protected string $baseUrl;

  /**
   * The API key for authentication.
   *
   * @var string
   */
  protected string $apiKey;

  /**
   * Request timeout in seconds.
   *
   * @var int
   */
  protected int $timeout;

  /**
   * Debug mode flag.
   *
   * @var bool
   */
  protected bool $debug;

  /**
   * Constructs a new LlmdClient.
   *
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   The HTTP client.
   * @param \Drupal\key\KeyRepositoryInterface $keyRepository
   *   The key repository.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger
   *   Logger.
   */
  public function __construct(protected ClientInterface $httpClient, protected KeyRepositoryInterface $keyRepository, protected LoggerChannelFactoryInterface $logger) {}

  /**
   * Set the configuration for the client.
   *
   * @param string $base_url
   *   The base URL for the LLM-d orchestrator.
   * @param string $api_key_id
   *   The API key identifier in the key module.
   * @param int $timeout
   *   Request timeout in seconds.
   * @param bool $debug
   *   Enable debug mode.
   *
   * @throws \InvalidArgumentException
   *   If configuration parameters are invalid.
   *
   * @SuppressWarnings(PHPMD.MissingImport)
   * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
   */
  public function setConfiguration(string $base_url, string $api_key_id, int $timeout = 30, bool $debug = FALSE): void {
    // Validate and sanitize base URL.
    $this->validateAndSetBaseUrl($base_url);

    // Validate timeout (between 1 and 300 seconds)

    $this->timeout = $timeout;
    $this->debug = $debug;

    // Get and validate the API key from the key module.
    $this->validateAndSetApiKey($api_key_id);
  }

  /**
   * Get available models from the LLM-d orchestrator.
   *
   * @return array
   *   Array of available models.
   *
   * @throws \Exception
   *   If the request fails.
   *
   * @SuppressWarnings(PHPMD.MissingImport)
   */
  public function getModels(): array {
    try {
      $response = $this->makeRequest('GET', '/v1/models');
      $data = json_decode($response->getBody()->getContents(), TRUE);

      if (isset($data['data']) && is_array($data['data'])) {
        return array_map(function ($model) {
          return [
            'id' => $model['id'],
            'object' => $model['object'] ?? 'model',
            'description' => $model['metadata']['description'] ?? NULL,
            'max_tokens' => $model['metadata']['max_tokens'] ?? NULL,
            'dimensions' => $model['metadata']['dimensions'] ?? NULL,
            'created' => $model['created'] ?? time(),
            'owned_by' => $model['owned_by'] ?? 'llm-d',
          ];
        }, $data['data']);
      }

      return [];
    }
    catch (RequestException $e) {
      if ($this->debug) {
        $this->logger->get('llmd')->error($e->getMessage());
        $this->logger->get('ai_provider_llmd')->error('Failed to get models: @error', ['@error' => $e->getMessage()]);
      }
      throw new \Exception('Failed to retrieve models from LLM-d orchestrator: ' . $e->getMessage());
    }
  }

  /**
   * Create a chat completion.
   *
   * @param array $payload
   *   The chat completion payload.
   *
   * @return array
   *   The completion response.
   *
   * @throws \Exception
   *   If the request fails.
   *
   * @SuppressWarnings(PHPMD.MissingImport)
   */
  public function chatCompletion(array $payload): array {
    try {
      $response = $this->makeRequest('POST', '/v1/chat/completions', $payload);
      $body = $response->getBody()->getContents();
      return json_decode($body, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (RequestException $e) {
      $this->logger->get('ai_provider_llmd')->error('Chat completion failed: @error', ['@error' => $e->getMessage()]);
      throw new \Exception('Chat completion failed: ' . $e->getMessage());
    }
    catch (\JsonException $e) {
      $logger = $this->logger->get('ai_provider_llmd');
      $logger->error('Invalid (broken JSON) response reeceived: @body ', [
        '@body' => $body ?? 'No valid body received in response',
      ]);
      $logger->error('JSON error: @error reported', ['@error' => $e->getMessage()]);
    }
  }

  /**
   * Create a text completion.
   *
   * @param array $payload
   *   The completion payload.
   *
   * @return array
   *   The completion response.
   *
   * @throws \Exception
   *   If the request fails.
   *
   * @SuppressWarnings(PHPMD.MissingImport)
   */
  public function completion(array $payload): array {
    try {
      $response = $this->makeRequest('POST', '/v1/completions', $payload);
      return json_decode($response->getBody()->getContents(), TRUE);
    }
    catch (RequestException $e) {
      if ($this->debug) {
        $this->logger->get('ai_provider_llmd')->error('Completion failed: @error', ['@error' => $e->getMessage()]);
      }
      throw new \Exception('Completion failed: ' . $e->getMessage());
    }
  }

  /**
   * Create embeddings.
   *
   * @param array $payload
   *   The embeddings payload.
   *
   * @return array
   *   The embeddings response.
   *
   * @throws \Exception
   *   If the request fails.
   *
   * @SuppressWarnings(PHPMD.MissingImport)
   */
  public function embeddings(array $payload): array {
    try {
      $response = $this->makeRequest('POST', '/v1/embeddings', $payload);
      return json_decode($response->getBody()->getContents(), TRUE);
    }
    catch (RequestException $e) {
      if ($this->debug) {
        $this->logger->get('ai_provider_llmd')->error('Embeddings failed: @error', ['@error' => $e->getMessage()]);
      }
      throw new \Exception('Embeddings failed: ' . $e->getMessage());
    }
  }

  /**
   * Check the health of the LLM-d orchestrator.
   *
   * @return bool
   *   TRUE if healthy, FALSE otherwise.
   */
  public function health(): bool {
    try {
      $response = $this->makeRequest('GET', '/health');
      $data = json_decode($response->getBody()->getContents(), TRUE);
      return isset($data['status']) && $data['status'] === 'healthy';
    }
    catch (RequestException $e) {
      if ($this->debug) {
        $this->logger->get('ai_provider_llmd')->error('Health check failed: @error', ['@error' => $e->getMessage()]);
      }
      return FALSE;
    }
  }

  /**
   * Make an HTTP request to the LLM-d orchestrator.
   *
   * @param string $method
   *   The HTTP method.
   * @param string $endpoint
   *   The API endpoint.
   * @param array|null $payload
   *   The request payload.
   *
   * @return \Psr\Http\Message\ResponseInterface
   *   The HTTP response.
   *
   * @throws \GuzzleHttp\Exception\RequestException
   *   If the request fails.
   *
   * @SuppressWarnings(PHPMD.MissingImport)
   */
  protected function makeRequest(string $method, string $endpoint, ?array $payload = NULL): ResponseInterface {
    // Validate endpoint.
    if (!$this->isValidEndpoint($endpoint)) {
      throw new \InvalidArgumentException('Invalid API endpoint provided.');
    }

    // Sanitize payload.
    if ($payload !== NULL) {
      $payload = $this->sanitizePayload($payload);
    }

    $options = [
      'timeout' => $this->timeout,
      'headers' => [
        'Content-Type' => 'application/json',
        'User-Agent' => 'Drupal-AI-Provider-LLMd/1.0',
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
      ],
      // Enforce SSL certificate verification.
      'verify' => TRUE,
    ];

    // Add API key if available.
    if (!empty($this->apiKey)) {
      $options['headers']['X-API-Key'] = $this->apiKey;
    }

    // Add JSON payload for POST requests.
    if ($payload !== NULL) {
      $options['json'] = $payload;
    }

    $url = $this->baseUrl . $endpoint;

    // Security logging for debugging (without sensitive data)
    if ($this->debug) {
      $this->logger->get('ai_provider_llmd')->debug('Making @method request to @url', [
        '@method' => $method,
        '@url' => $url,
      ]);
    }

    // Log security events.
    $this->logger->get('ai_provider_llmd')->info('API request: @method @endpoint', [
      '@method' => $method,
      '@endpoint' => $endpoint,
    ]);

    return $this->httpClient->request($method, $url, $options);
  }

  /**
   * Validate and set the base URL with SSRF protection.
   *
   * @param string $base_url
   *   The base URL to validate.
   *
   * @throws \InvalidArgumentException
   *   If the URL is invalid or potentially malicious.
   *
   * @SuppressWarnings(PHPMD.MissingImport)
   */
  private function validateAndSetBaseUrl(string $base_url): void {
    // Remove any trailing slashes.
    $base_url = rtrim($base_url, '/');

    // Validate URL format.
    if (!filter_var($base_url, FILTER_VALIDATE_URL)) {
      throw new \InvalidArgumentException('Invalid URL format provided.');
    }

    $parsed_url = parse_url($base_url);

    // Ensure HTTPS is used for production.
    if (!isset($parsed_url['scheme']) || !in_array($parsed_url['scheme'], ['http', 'https'])) {
      throw new \InvalidArgumentException('Only HTTP and HTTPS protocols are allowed.');
    }

    // Check for SSRF protection - block internal IPs.
    if (isset($parsed_url['host'])) {
      $host = $parsed_url['host'];

      // Allow localhost and host.docker.internal for development.
      $allowed_dev_hosts = ['localhost', '127.0.0.1', 'host.docker.internal'];

      if (!in_array($host, $allowed_dev_hosts) && $this->isInternalIp($host)) {
        throw new \InvalidArgumentException('Requests to internal IP addresses are not allowed.');
      }
    }

    $this->baseUrl = $base_url;
  }

  /**
   * Validate and set the API key.
   *
   * @param string $api_key_id
   *   The API key identifier.
   *
   * @throws \InvalidArgumentException
   *   If the API key is invalid.
   *
   * @SuppressWarnings(PHPMD.MissingImport)
   */
  private function validateAndSetApiKey(string $api_key_id): void {
    if (empty($api_key_id)) {
      throw new \InvalidArgumentException('API key ID cannot be empty.');
    }

    $key = $this->keyRepository->getKey($api_key_id);
    if (!$key) {
      throw new \InvalidArgumentException('API key not found in key repository.');
    }

    $api_key = $key->getKeyValue();
    if (empty($api_key)) {
      throw new \InvalidArgumentException('API key value is empty.');
    }

    // Basic API key format validation (adjust as needed).
    if (strlen($api_key) < 8) {
      throw new \InvalidArgumentException('API key appears to be too short.');
    }

    $this->apiKey = $api_key;
  }

  /**
   * Check if an IP address is internal/private.
   *
   * @param string $host
   *   The hostname or IP address.
   *
   * @return bool
   *   TRUE if the IP is internal/private.
   */
  private function isInternalIp(string $host): bool {
    // Convert hostname to IP if needed.
    $ip = gethostbyname($host);

    if ($ip === $host && !filter_var($ip, FILTER_VALIDATE_IP)) {
      // If gethostbyname didn't resolve, it might be a domain.
      return FALSE;
    }

    // Check for private/internal IP ranges.
    return !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
  }

  /**
   * Validate API endpoint.
   *
   * @param string $endpoint
   *   The endpoint to validate.
   *
   * @return bool
   *   TRUE if valid.
   */
  private function isValidEndpoint(string $endpoint): bool {
    // Allow only specific endpoints.
    $allowed_endpoints = [
      '/health',
      '/v1/models',
      '/v1/chat/completions',
      '/v1/completions',
      '/v1/embeddings',
    ];

    return in_array($endpoint, $allowed_endpoints);
  }

  /**
   * Validate payload data structure.
   *
   * @param array $payload
   *   The payload to validate.
   *
   * @return array
   *   The validated payload.
   *
   * @SuppressWarnings(PHPMD.MissingImport)
   */
  private function sanitizePayload(array $payload): array {
    // Basic validation - ensure payload is not excessively large.
    // 1MB limit.
    if (json_encode($payload) && strlen(json_encode($payload)) > 1048576) {
      throw new \InvalidArgumentException('Payload too large.');
    }

    return $payload;
  }

}
