# Reseller Settled Usage Display - Implementation

## Overview
This implementation adds visibility of "settled usage" to resellers in their config list and detail views.

## What is Settled Usage?

When a reseller resets a config's usage (via the reseller panel), the current `usage_bytes` is moved to `meta.settled_usage_bytes`. This happens when:
- A reseller customer finishes their quota and needs a reset
- The reseller wants to give their customer more usage on the same config

**Important:** Settled usage is NOT "historical" or "previous cycle" usage - it counts as **current usage** for the reseller. The customer consumed that traffic, so it counts against the reseller's quota.

## How It Works

### Config Reset (Reseller Action)
When a reseller resets a config via `ConfigController@resetUsage`:
1. Current `usage_bytes` → `meta.settled_usage_bytes` (accumulates)
2. `usage_bytes` set to 0
3. Remote panel reset attempted
4. Config is ready for customer to use again

### Reseller Usage Calculation
`SyncResellerUsageJob` already calculates reseller total as:
```php
$totalUsage = sum(config.usage_bytes + config.meta.settled_usage_bytes)
```

This ensures settled usage counts against the reseller's quota.

### Admin Reset (Admin Action)
When admin resets reseller's usage from admin panel:
- Only `reseller.traffic_used_bytes` is set to 0
- Individual config usage NOT touched
- Remote panels NOT reset
- This is effectively giving the reseller fresh quota

## Changes Made

### 1. UI Updates
**Files Modified:**
- `Modules/Reseller/resources/views/configs/index.blade.php`
- `Modules/Reseller/resources/views/configs/edit.blade.php`

**What Changed:**
- Added "مصرف قبلی" (Settled Usage) column to configs list
- Shows `meta.settled_usage_bytes` in GB format
- Only displays when settled usage > 0
- Responsive: column on desktop, inline text on mobile

### 2. Tests
**New File:**
- `tests/Feature/ResellerSettledUsageTest.php`

**Test Coverage:**
1. `test_settled_usage_counts_as_current_reseller_usage()` - Verifies settled usage is included in reseller total
2. `test_settled_usage_display_method_works()` - Verifies `getSettledUsageBytes()` and `getTotalUsageBytes()` methods
3. `test_config_with_no_settled_usage_returns_zero()` - Edge case handling

All tests pass (7 assertions).

## Database Schema
No migrations needed. Uses existing fields:
- `reseller_configs.usage_bytes` (current cycle usage)
- `reseller_configs.meta->settled_usage_bytes` (accumulated from resets)
- `reseller_configs.meta->last_reset_at` (timestamp of last reset)

## Existing Behavior (Unchanged)

### Config Reset (by Reseller)
- Already implemented in `ConfigController@resetUsage()`
- Already moves usage to settled
- Already attempts remote reset
- Already recomputes reseller total including settled
- **No changes needed - working as designed**

### Admin Reset (by Admin)
- Already implemented in `EditReseller.php`
- Already resets only `reseller.traffic_used_bytes`
- Already creates audit log
- Already re-enables configs if quota available
- **No changes needed - working as designed**

### Sync Job
- `SyncResellerUsageJob` already includes settled in total
- Already updates `reseller.traffic_used_bytes` correctly
- **No changes needed - working as designed**

## What This PR Adds

**Only one thing:** UI visibility of settled usage to resellers.

Before: Resellers couldn't see settled usage in their panel
After: Resellers can see settled usage alongside current usage

That's it. Everything else already worked correctly.

## Post-Deployment

### Verification Steps
1. Login as a reseller
2. Navigate to configs list
3. Verify "مصرف قبلی" column visible (if any configs have settled usage)
4. Check a config detail page - settled usage should show if present

### No Other Changes Needed
- No queue jobs to monitor
- No database updates
- No config changes
- No remote panel interactions

## Files Changed

**Modified (2):**
1. `Modules/Reseller/resources/views/configs/index.blade.php`
2. `Modules/Reseller/resources/views/configs/edit.blade.php`

**Added (1):**
3. `tests/Feature/ResellerSettledUsageTest.php`

**Total:** 3 files (2 modified, 1 added)

## Backward Compatibility
- Existing settled usage in `meta` is preserved
- Configs without settled usage show "-" in UI
- All existing functionality unchanged

## Success Criteria ✅
- [x] Reseller configs list shows settled usage (GB) from `meta.settled_usage_bytes`
- [x] Settled usage only displays when > 0
- [x] Model methods (`getSettledUsageBytes()`, `getTotalUsageBytes()`) work correctly
- [x] Tests passing (7 assertions)
- [x] No syntax errors
- [x] Blade templates compile successfully
