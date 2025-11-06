# Manual QA Guide for Reseller Config Re-Enable Fix

## Overview
This fix improves the robustness of the `ReenableResellerConfigsJob` to ensure configs are reliably re-enabled when resellers are reactivated, regardless of meta marker variations.

## Changes Summary
1. Enhanced JSON query to detect string 'true' in addition to boolean true, string '1', and integer 1
2. Added PHP fallback filter to catch configs missed by JSON query
3. Added detailed logging for config detection statistics
4. Fixed closure scope bug in PHP fallback filter

## What Was Fixed
**Problem**: Configs remained disabled after reseller reactivation on Eylandoo panel
**Root Cause**: JSON query missed configs with string 'true' marker
**Solution**: Added string 'true' to query + PHP fallback filter

## Manual Testing Steps

### Test Case 1: Normal Reactivation Flow
1. Create a test reseller with traffic quota (e.g., 10GB)
2. Create a config for that reseller
3. Exhaust the reseller's traffic quota (or expire window)
4. Verify reseller status changes to 'suspended' and config status changes to 'disabled'
5. Recharge/extend the reseller (add traffic or extend window)
6. Wait for scheduler tick (1 minute) or manually dispatch: `php artisan tinker` then `\Modules\Reseller\Jobs\ReenableResellerConfigsJob::dispatch($resellerId)`
7. **Expected**: Config status should change to 'active'
8. **Expected**: Config meta should have suspension markers removed

### Test Case 2: Eylandoo Panel Configs
1. Create a test reseller with Eylandoo panel config
2. Suspend reseller (exhaust quota)
3. Verify config is disabled on remote Eylandoo panel
4. Recharge reseller
5. Wait for re-enable job
6. **Expected**: Config is active in DB
7. **Expected**: Config is enabled on remote Eylandoo panel (check panel directly)

### Test Case 3: Multiple Marker Types (Legacy Data)
This tests the PHP fallback filter.

1. Using DB tool or Laravel Tinker, create configs with various marker types:
   ```php
   // Config with string 'true'
   $config1->update(['status' => 'disabled', 'meta' => ['disabled_by_reseller_suspension' => 'true']]);
   
   // Config with integer 1
   $config2->update(['status' => 'disabled', 'meta' => ['disabled_by_reseller_suspension' => 1]]);
   
   // Config with no explicit marker but has disabled_by_reseller_id
   $config3->update(['status' => 'disabled', 'meta' => ['disabled_by_reseller_id' => $resellerId]]);
   ```

2. Reactivate the reseller
3. Run re-enable job
4. **Expected**: All configs should be re-enabled regardless of marker type

### Test Case 4: Remote API Failure
1. Temporarily make a panel unreachable (change panel URL to invalid)
2. Create config for that panel
3. Suspend and reactivate reseller
4. **Expected**: Config status becomes 'active' in DB even though remote enable failed
5. **Expected**: Log shows warning about remote failure but success on DB update
6. **Expected**: ResellerConfigEvent records remote_success = false

### Test Case 5: Idempotent Behavior
1. Suspend reseller
2. Reactivate reseller
3. Run re-enable job multiple times
4. **Expected**: No errors, configs remain active, no duplicate events

### Test Case 6: Check Logs
After any of the above tests, check logs for:
```
[INFO] Config detection for reseller {id}
  - from_json_query: X
  - from_php_filter: Y
  - total_unique: Z
```

This helps diagnose which detection method found the configs.

## Key Observability Points

### Scheduler Logs
Look for:
```
[INFO] Scheduler tick: ReenableResellerConfigsJob - dispatching to queue
[INFO] Starting reseller config re-enable job
[INFO] Config detection for reseller {id}
[INFO] Re-enabling X configs for reseller {id}
[INFO] Attempting to re-enable config
[INFO] Config {id} re-enabled in DB (status set to active, meta flags cleared)
```

### Failure Scenarios
If configs are NOT re-enabled, check logs for:
1. "No configs marked for re-enable" - Indicates detection failed
2. "Skipping reseller" - Indicates reseller not eligible (no traffic/invalid window)
3. "No eligible resellers" - Indicates reseller query filtering out target

### Detection Statistics
The new logging will show:
- `from_json_query`: How many configs found by SQL JSON query
- `from_php_filter`: How many configs found by PHP fallback
- `total_unique`: Total configs to re-enable (deduplicated)

If `from_php_filter` > 0, it means some configs were missed by JSON query and caught by fallback.

## Rollback Plan
If issues arise:
1. Revert to previous version of ReenableResellerConfigsJob.php
2. The old version still works, just misses configs with string 'true' marker

## Known Limitations
- Configs must have at least one of these markers to be detected:
  - `disabled_by_reseller_suspension` (any truthy value)
  - `suspended_by_time_window` (any truthy value)
  - `disabled_by_reseller_id` (matching reseller ID)
- Configs disabled manually by admin (without suspension markers) will NOT be auto-enabled

## Success Criteria
✅ Configs are re-enabled reliably after reseller reactivation
✅ Eylandoo configs work correctly
✅ All marker variations (true, '1', 1, 'true') are detected
✅ Remote API failures don't prevent DB status update
✅ Job is idempotent (can run multiple times safely)
✅ Detailed logs aid debugging
