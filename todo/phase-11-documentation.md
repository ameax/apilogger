# Phase 11: Documentation & Examples

**Status**: PENDING
**Priority**: HIGH
**Estimated Time**: 2-3 hours
**Dependencies**: Phases 7-10 must be completed

## Objectives
- Complete comprehensive documentation for outbound logging feature
- Create practical integration examples
- Update README with new capabilities
- Ensure smooth adoption for developers

## Tasks

### 1. Update Main README.md
- [ ] Add new section for Outbound API Logging
  - Feature overview and benefits
  - Quick start guide
  - Configuration examples
  - Comparison table (inbound vs outbound)
- [ ] Update installation instructions
  - Mention Guzzle as suggested dependency
  - Add composer require command with Guzzle
- [ ] Update feature list
- [ ] Add badges if applicable

### 2. Create Detailed Documentation
- [ ] Create `docs/outbound-logging.md`
  - Complete configuration reference
  - Middleware registration methods
  - Filtering options explained
  - Correlation ID usage
  - Performance considerations
  - Troubleshooting guide

### 3. Create Integration Examples
- [ ] Create `examples/outbound/` directory
- [ ] Basic Guzzle client example
  ```php
  examples/outbound/basic-client.php
  ```
- [ ] Service with custom configuration
  ```php
  examples/outbound/custom-service.php
  ```
- [ ] Multiple services with correlation
  ```php
  examples/outbound/correlated-requests.php
  ```
- [ ] Error handling and retries
  ```php
  examples/outbound/retry-handling.php
  ```

### 4. Real-World Integration Examples
- [ ] Laravel HTTP Client integration example
- [ ] Haufe360ApiService integration example
- [ ] AmApiService integration example
- [ ] Example with custom filters
- [ ] Example with per-service configuration

### 5. Migration Guide
- [ ] Create `docs/migration-guide.md`
  - For users updating from earlier versions
  - Configuration changes needed
  - How to enable outbound logging
  - Backwards compatibility notes

### 6. API Reference
- [ ] Document all public methods
  - OutboundApiLogger methods
  - ServiceRegistry methods
  - Filter service methods
  - Model scopes
- [ ] Add PHPDoc blocks where missing

### 7. Testing Documentation
- [ ] Document how to test with outbound logging
- [ ] Mock examples for unit tests
- [ ] Integration test examples

### 8. Update CHANGELOG.md
- [ ] Add version entry for outbound logging feature
- [ ] List all new features
- [ ] Note any breaking changes (should be none)
- [ ] Credit contributors

### 9. Final Quality Checks
- [ ] Ensure all code examples work
- [ ] Check for typos and grammar
- [ ] Verify all links work
- [ ] Test installation instructions
- [ ] Run full test suite one final time

## Documentation Structure

### README.md Outline
```markdown
## Features
- ✅ Inbound API request logging
- ✅ **NEW: Outbound API call logging**
- ✅ Multiple storage backends
- ...

## Outbound API Logging

Log all external API calls made by your application...

### Quick Start
1. Install with Guzzle
2. Enable in config
3. Register your service
4. Start logging!

### Configuration
...
```

### Example Code Template
```php
<?php
// examples/outbound/basic-client.php

use GuzzleHttp\Client;
use Ameax\ApiLogger\Outbound\GuzzleHandlerStackFactory;

// Create a Guzzle client with automatic logging
$stack = GuzzleHandlerStackFactory::create();
$client = new Client([
    'handler' => $stack,
    'base_uri' => 'https://api.example.com',
]);

// All requests through this client will be logged
$response = $client->get('/users');
```

## Acceptance Criteria
- [ ] README clearly explains new features
- [ ] All examples are tested and working
- [ ] Documentation is clear and comprehensive
- [ ] Migration path is documented
- [ ] API reference is complete
- [ ] No broken links or references

## Distribution Checklist
- [ ] README.md updated
- [ ] CHANGELOG.md updated
- [ ] All documentation files created
- [ ] All examples tested
- [ ] Package version bumped (if needed)
- [ ] GitHub issue closed with reference

## Notes
- Focus on practical, real-world examples
- Keep documentation concise but complete
- Ensure consistency with existing documentation style
- Consider adding diagrams for complex concepts
- Test all code examples before including

## Completion Checklist
- [ ] All documentation tasks completed
- [ ] All examples working
- [ ] Peer review of documentation
- [ ] Final test suite run passing
- [ ] This file updated to COMPLETED status
- [ ] Changes committed with descriptive message
- [ ] Ready for release/merge to main