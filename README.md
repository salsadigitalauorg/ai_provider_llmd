# LLM-d AI Provider

This module provides integration between Drupal AI and RedHat's LLM-d distributed inference framework.

## Overview

LLM-d (Large Language Model daemon) is a distributed inference framework that allows you to run multiple LLM models across different containers and route requests efficiently. This provider enables Drupal sites to leverage LLM-d's orchestration capabilities.

## Features

- **Distributed Model Support**: Connect to multiple LLM models through a single orchestrator
- **OpenAI-Compatible API**: Uses standard OpenAI API format for compatibility
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
- **Embeddings**: Text-to-vector conversion for semantic search and similarity matching

## Usage

### Chat Operations
Once configured, LLM-d models will be available in the AI module's model selection for chat operations.

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
- `/v1/chat/completions` - Chat completions
- `/v1/embeddings` - Text embeddings (for vector database support)
- `/health` - Health check

## Troubleshooting

1. **Connection Issues**: Use the "Test Connection" button in settings
2. **No Models**: Ensure your LLM-d orchestrator is running and accessible
3. **API Errors**: Enable debug mode for detailed error logging
4. **Authentication**: Verify your API key is correct and has proper permissions
5. **Embeddings Issues**: 
   - Ensure your LLM-d orchestrator implements `/v1/embeddings` endpoint
   - Verify embedding model dimensions match your vector database configuration
   - Check that embedding models are properly loaded in your orchestrator
6. **Vector Database Issues**:
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
