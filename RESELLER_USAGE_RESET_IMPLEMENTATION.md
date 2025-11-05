# Reseller Usage Reset and Settled Usage Implementation

## Overview
This implementation addresses two critical issues with reseller config usage management:

1. **Settled Usage Visibility**: Resellers can now see their accumulated "settled" usage (from previous resets) in the configs list and detail views.
2. **True Reseller-Wide Usage Reset**: Admin reset now properly zeros usage across all configs and remote panels, preventing usage from "coming back" after the next sync.

## Changes Made

### A) Settled Usage Display
**Modified Files:**
- `Modules/Reseller/resources/views/configs/index.blade.php`
- `Modules/Reseller/resources/views/configs/edit.blade.php`

**What Changed:**
- Added a "مصرف قبلی" (Previous Usage) column in the configs list table
- Displays `meta.settled_usage_bytes` converted to GB
- Shows on both desktop (as a column) and mobile (inline with other info)
- Only displays when settled usage > 0 to avoid clutter

### B) ResetResellerUsageJob
**New File:**
- `Modules/Reseller/Jobs/ResetResellerUsageJob.php`

**Functionality:**
1. Accepts a `Reseller` model instance
2. Iterates through all configs belonging to that reseller
3. For each config:
   - Moves `usage_bytes` into `meta.settled_usage_bytes` (accumulates)
   - Sets `usage_bytes = 0`
   - For Eylandoo: also zeros `meta.used_traffic` and `meta.data_used`
   - Calls `ResellerProvisioner->resetUserUsage()` to reset on remote panels
   - Creates a `ResellerConfigEvent` with telemetry (remote success/fail)
4. After processing all configs:
   - Recomputes `reseller.traffic_used_bytes` as sum of current `usage_bytes` only
   - Creates an `AuditLog` entry with counts and statistics

**Key Features:**
- Remote panel resets attempted for Marzban, Marzneshin, X-UI, and Eylandoo
- Comprehensive error handling and logging
- Transaction-based for data consistency
- Audit trail for compliance

### C) Admin UI Update
**Modified File:**
- `app/Filament/Resources/ResellerResource/Pages/EditReseller.php`

**What Changed:**
- `reset_usage` action now dispatches `ResetResellerUsageJob` instead of direct DB update
- Updated modal description to explain the background process
- Added logging for job dispatch
- Creates an initial audit log entry for tracking
- Shows notification that reset has started in background

### D) Testing
**New File:**
- `tests/Feature/ResellerUsageResetTest.php`

**Test Coverage:**
1. `test_reset_job_zeros_config_usage_and_settles()`: Verifies configs are reset, settled usage accumulates, and reseller aggregate is recomputed
2. `test_reset_job_handles_eylandoo_configs()`: Verifies Eylandoo-specific meta fields are properly zeroed
3. `test_settled_usage_visible_in_reseller_configs_view()`: Verifies model method `getSettledUsageBytes()` works correctly

All tests pass with 14 assertions.

## How It Works

### Before This Change
1. Admin clicks "Reset Usage" → only `reseller.traffic_used_bytes` set to 0
2. Per-config `usage_bytes` remain unchanged
3. Remote panels not reset
4. Next sync: `SyncResellerUsageJob` recomputes reseller total from configs → usage "comes back"

### After This Change
1. Admin clicks "Reset Usage" → job dispatched in background
2. Job processes each config:
   - Current usage moved to settled
   - Local `usage_bytes` set to 0
   - Remote panel reset attempted
3. Reseller aggregate recomputed from new (zero) values
4. Next sync: remote panels return 0, sync maintains the reset state

## Database Schema
No migrations needed. Uses existing fields:
- `reseller_configs.usage_bytes` (current usage)
- `reseller_configs.meta->settled_usage_bytes` (accumulated from resets)
- `reseller_configs.meta->last_reset_at` (timestamp of last reset)
- `reseller_configs.meta->used_traffic` (Eylandoo specific)
- `reseller_configs.meta->data_used` (Eylandoo specific)

## API Behavior
The job uses existing `ResellerProvisioner->resetUserUsage()` method which handles:
- **Marzban**: Calls `resetUserUsage()` on panel
- **Marzneshin**: Calls `resetUserUsage()` on panel
- **X-UI**: Calls `resetUserUsage()` on panel
- **Eylandoo**: Calls `resetUserUsage()` on panel

All with retry logic (3 attempts with exponential backoff).

## Backward Compatibility
- Existing settled usage in `meta.settled_usage_bytes` is preserved and accumulated
- Configs without settled usage show "-" in UI
- `SyncResellerUsageJob` behavior unchanged (still sums only current `usage_bytes`)
- Reseller dashboard counter shows current usage only

## Post-Deployment Steps
1. Run `php artisan optimize:clear`
2. From admin panel, test reset on a test reseller
3. Watch logs: `tail -f storage/logs/laravel.log | grep -i reset`
4. In reseller panel, verify:
   - Configs show "Settled usage" column
   - Dashboard counter stays 0 after reset
   - Subsequent sync doesn't resurrect usage

## Security & Audit
- All resets create `AuditLog` entries
- Each config reset creates a `ResellerConfigEvent`
- Remote reset success/failure tracked
- Admin action attribution via audit logs

## Performance
- Job runs in queue (asynchronous)
- No timeout issues for resellers with many configs
- Rate limiting applied for remote panel calls (3 ops/sec)
