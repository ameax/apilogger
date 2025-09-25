# Phase 7: Configuration & Feature Flags

**Status**: COMPLETED ✅
**Priority**: HIGH
**Estimated Time**: 2-3 hours
**Completion Date**: 2025-09-25

## Objectives
- Add feature flag structure for independent inbound/outbound control
- Update configuration file with new options
- Add conditional registration in Service Provider
- Add Guzzle as suggested dependency

## Tasks

### 1. Update Configuration Structure
- [x] Modify `config/apilogger.php` with feature flags
  - Add `features.inbound.enabled` flag (default: true)
  - Add `features.outbound.enabled` flag (default: false)
  - Add `features.outbound.services.include` array
  - Add `features.outbound.services.exclude` array
  - Add `features.outbound.hosts.include` array
  - Add `features.outbound.hosts.exclude` array
  - Add `features.outbound.auto_register` flag
  - Keep existing config structure for shared settings (storage, privacy, performance)

### 2. Update Service Provider
- [x] Modify `ApiLoggerServiceProvider.php`
  - Add `registerFeatures()` method
  - Conditional registration for inbound middleware
  - Conditional registration for outbound features
  - Check for Guzzle availability before registering outbound
  - Log warning if outbound enabled but Guzzle not installed

### 3. Update Composer Dependencies
- [x] Add Guzzle to "suggest" section in composer.json
  - "guzzlehttp/guzzle": "Required for outbound API logging (^7.0)"
- [x] Update package keywords if needed

### 4. Testing
- [x] Write tests for feature flag logic
  - Test with only inbound enabled
  - Test with only outbound enabled
  - Test with both enabled
  - Test with both disabled
- [x] Test Service Provider with different flag combinations
- [x] Test graceful degradation when Guzzle not available
- [x] Ensure all existing tests still pass

### 5. Quality Checks
- [x] Run PHPStan level 8: `vendor/bin/phpstan analyse`
- [x] Run Pint for code formatting: `vendor/bin/pint`
- [x] Run full test suite: `vendor/bin/pest`

## Discussion Points
1. **Auto-registration**: Should we auto-detect and register all Guzzle clients, or require explicit registration per service?
   - Decision: Require explicit registration for better control

2. **Default values**: What should be the default values for feature flags?
   - Decision: inbound: true (backwards compatible), outbound: false (opt-in)

3. **Guzzle version**: Should we check for specific Guzzle version?
   - Decision: Support Guzzle 7.0+ (current stable)

4. **Config structure**: Nested features array or flat structure?
   - Decision: Nested for better organization and future extensibility

## Acceptance Criteria
- [x] Inbound and outbound logging can be independently enabled/disabled via config
- [x] Configuration is well-documented with inline comments
- [x] Service Provider handles missing Guzzle gracefully with informative warning
- [x] All existing tests pass
- [x] New tests cover all feature flag scenarios
- [x] No breaking changes to existing functionality

## Notes
- Package is not yet in production, so we can modify existing structures freely
- Existing migration can be updated in later phase if needed
- Focus on clean separation between inbound and outbound features
- Consider future extensibility for other logging types (e.g., database queries, cache operations)

## Completion Checklist
- [x] All tasks completed
- [x] All tests passing (209 passed, 1 skipped)
- [x] PHPStan level 8 passing
- [x] Code formatted with Pint
- [x] This file updated to COMPLETED status
- [x] Changes committed with descriptive message