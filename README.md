# LLM-d AI Provider

This module provides integration between Drupal AI and RedHat's LLM-d distributed inference framework.

## Overview

LLM-d (Large Language Model daemon) is a distributed inference framework that allows you to run multiple LLM models across different containers and route requests efficiently. This provider enables Drupal sites to leverage LLM-d's orchestration capabilities.

## Features

- **Distributed Model Support**: Connect to multiple LLM models through a single orchestrator
- **OpenAI-Compatible API**: Uses standard OpenAI API format for compatibility
- **Real-Time Streaming**: Server-Sent Events (SSE) streaming for real-time token delivery
- **Embeddings Support**: Generate text embeddings for vector database integration
- **Vector Database Integration**: Compatible with Milvus and PostgreSQL vector databases
- **Health Monitoring**: Connection testing and health check capabilities
- **Debug Support**: Comprehensive logging for troubleshooting

## Requirements

- Drupal 10.2+ or 11+
- AI module
- Key module
- Running LLM-d orchestrator instance
- For vector database integration:
  - ai_vdb_provider_milvus (for Milvus integration)
  - ai_vdb_provider_postgres (for PostgreSQL vector integration)

## Installation

1. Place this module in your `modules/contrib` directory
2. Enable the module: `drush en ai_provider_llmd`
3. Configure your LLM-d settings at `/admin/config/ai/providers/llmd`

## Configuration

### LLM-d Orchestrator Setup

1. **Host URL**: The base URL of your LLM-d orchestrator (e.g., `http://localhost:8000`)
2. **API Key**: Create a key in the Key module containing your LLM-d API key
3. **Timeout**: Request timeout in seconds (default: 30)
4. **Debug**: Enable detailed logging for troubleshooting

### API Key Setup

1. Go to `/admin/config/system/keys`
2. Create a new key with your LLM-d API key
3. Select this key in the LLM-d provider configuration

## Supported Operations

Currently supports:
- **Chat Completions**: Conversational AI with message history
- **Streaming Chat Completions**: Real-time token streaming using Server-Sent Events (SSE)
- **Embeddings**: Text-to-vector conversion for semantic search and similarity matching

## Usage

### Chat Operations
Once configured, LLM-d models will be available in the AI module's model selection for chat operations.

### 🔄 Streaming Operations
The LLM-d provider supports real-time token streaming for immediate response delivery:

#### Using the Streaming Client Methods

```php
// Get the LLM-d provider service
$provider = \Drupal::service('ai.provider.llmd');

// Create a ChatInput with your messages
$input = new ChatInput([
  new ChatMessage('user', 'Tell me a short story about artificial intelligence')
]);

// Use streaming chat method for real-time responses
foreach ($provider->streamingChat($input, 'mistral-7b') as $chunk) {
  // Process each streaming chunk as it arrives
  $content = $chunk['choices'][0]['delta']['content'] ?? '';
  if ($content) {
    echo $content; // Output token immediately
    flush();
  }
}
```

#### Direct Streaming Client Usage

```php
// Access the LLM-d client directly
$client = \Drupal::service('ai_provider_llmd.client');

// Configure client connection
$client->setConfiguration($host, $api_key, $timeout, $debug);

// Create streaming payload
$payload = [
  'model' => 'mistral-7b',
  'messages' => [
    ['role' => 'user', 'content' => 'Write a poem about technology']
  ],
  'stream' => true,
  'max_tokens' => 150
];

// Stream tokens in real-time
foreach ($client->streamingChatCompletion($payload) as $chunk) {
  // Handle each streaming chunk
  if (isset($chunk['choices'][0]['delta']['content'])) {
    echo $chunk['choices'][0]['delta']['content'];
    flush();
  }
}
```

#### Benefits of Streaming
- **Immediate Feedback**: Tokens appear as soon as they're generated
- **Better User Experience**: No waiting for complete response
- **Lower Perceived Latency**: Users see progress immediately
- **OpenAI Compatible**: Works with standard streaming patterns

### Vector Database Integration
For semantic search and vector similarity operations:

1. **Configure Embeddings**: Select an LLM-d embedding model in your AI configuration
2. **Set Up Vector Database**: Install and configure either:
   - `ai_vdb_provider_milvus` for Milvus integration
   - `ai_vdb_provider_postgres` for PostgreSQL with pgvector
3. **Vector Dimensions**: Ensure your vector database collection dimensions match your embedding model:
   - `text-embedding-ada-002`, `text-embedding-3-small`: 1536 dimensions
   - `text-embedding-3-large`: 3072 dimensions
   - `all-MiniLM-L6-v2`: 384 dimensions
   - `all-mpnet-base-v2`: 768 dimensions

## LLM-d Orchestrator

This provider connects to an LLM-d orchestrator that provides:

- **Model Registry**: Dynamic discovery of available chat and embedding models
- **Load Balancing**: Intelligent routing across model instances
- **Security**: Rate limiting, input validation, and audit logging
- **Monitoring**: Health checks and performance metrics

### Required Endpoints
Your LLM-d orchestrator must implement these OpenAI-compatible endpoints:
- `/v1/models` - List available models
- `/v1/chat/completions` - Chat completions (supports both streaming and non-streaming)
- `/v1/embeddings` - Text embeddings (for vector database support)
- `/health` - Health check

#### Streaming Support
The `/v1/chat/completions` endpoint supports streaming when:
- Request includes `"stream": true` parameter
- Request includes `Accept: text/event-stream` header
- Response uses Server-Sent Events (SSE) format with `data:` prefixed chunks

## Troubleshooting

1. **Connection Issues**: Use the "Test Connection" button in settings
2. **No Models**: Ensure your LLM-d orchestrator is running and accessible
3. **API Errors**: Enable debug mode for detailed error logging
4. **Authentication**: Verify your API key is correct and has proper permissions
5. **Embeddings Issues**: 
   - Ensure your LLM-d orchestrator implements `/v1/embeddings` endpoint
   - Verify embedding model dimensions match your vector database configuration
   - Check that embedding models are properly loaded in your orchestrator
6. **Streaming Issues**:
   - Verify your LLM-d orchestrator supports SSE streaming
   - Check that ALB/load balancer timeout settings allow streaming (900s+ recommended)
   - Ensure PHP `max_execution_time` allows for streaming duration
   - Test streaming with curl: `curl -H "Accept: text/event-stream" -d '{"stream":true,...}' endpoint`
7. **Vector Database Issues**:
   - Verify vector database provider modules are installed and configured
   - Ensure collection dimensions match embedding model output dimensions
   - Check vector database connectivity and permissions

## Development

This module follows Drupal coding standards and integrates with the AI module's plugin system.

### Architecture

- `LlmdClient`: HTTP client for LLM-d API communication (chat, embeddings, models)
- `LlmdAiProvider`: Main AI provider plugin implementing ChatInterface and EmbeddingsInterface
- `LlmdConfigForm`: Configuration form for admin settings

## License

This project is licensed under the GPL-2.0+ license.
