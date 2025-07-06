# Changelog

All notable changes to the LLM-d AI Provider module will be documented in this file.

## [1.0.0-dev] - 2025-07-06

### Added
- Initial release of LLM-d AI Provider
- Support for RedHat LLM-d distributed inference framework
- OpenAI-compatible API integration
- Chat completion support
- Dynamic model discovery
- Configuration form with connection testing
- Health check integration
- Debug logging support
- Security hardened communication
- Comprehensive documentation

### Features
- **Chat Interface**: Full chat completion support with message history
- **Model Discovery**: Automatic detection of available models from LLM-d orchestrator
- **Monitoring**: Health checks and connection testing
- **Configuration**: User-friendly admin interface for setup
- **Debugging**: Detailed logging for troubleshooting

### Technical Details
- Extends Drupal AI module's provider system
- Implements ChatInterface for conversational AI
- Uses Drupal Key module for secure API key storage
- HTTP client for reliable API communication
- Comprehensive error handling and logging
- OpenAI API format compatibility

### Requirements
- Drupal 10.2+ or 11+
- AI module
- Key module
- Running LLM-d orchestrator instance
