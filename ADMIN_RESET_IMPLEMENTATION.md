# Reseller Traffic Usage Display and Admin Reset Implementation

## Problem Statement

Two related bugs were identified:

1. **Display Issue**: The reseller UI was not showing the complete picture of traffic usage. It needed to display:
   - Current cycle usage (from active configs)
   - Settled usage (from previous resets)
   - Total usage (current + settled)

2. **Admin Reset Issue**: When an admin reset a reseller's usage:
   - The reset would be undone by the next sync job
   - Config usage would be recounted, defeating the purpose of the reset
   - There was no way to provide "quota forgiveness" to resellers

## Solution Overview

### A) Dashboard Display Improvements

**File**: `Modules/Reseller/Http/Controllers/DashboardController.php`

The controller now computes three separate metrics:
```php
$trafficCurrentBytes = $configs->sum('usage_bytes');
$trafficSettledBytes = $configs->sum(function ($config) {
    return (int) data_get($config->meta, 'settled_usage_bytes', 0);
});
$trafficUsedTotalBytes = $trafficCurrentBytes + $trafficSettledBytes;
```

**File**: `Modules/Reseller/resources/views/dashboard.blade.php`

The dashboard now displays:
- **Current Usage** (مصرف فعلی): Traffic used in current cycle
- **Settled Usage** (مصرف قبلی): Traffic from previous resets
- **Total Usage** (مجموع مصرف): Sum of both
- **Remaining**: Based on total usage vs quota

### B) Admin Forgiveness System

**New Database Field**: `resellers.admin_forgiven_bytes`

This field tracks traffic that the admin has forgiven from quota calculations.

**How It Works**:

1. **When Admin Resets** (`app/Filament/Resources/ResellerResource/Pages/EditReseller.php`):
   ```php
   $totalUsageFromConfigs = sum(config.usage_bytes + config.settled_usage_bytes);
   $reseller->admin_forgiven_bytes = $totalUsageFromConfigs;
   $reseller->traffic_used_bytes = 0;
   ```

2. **During Sync** (`Modules/Reseller/Jobs/SyncResellerUsageJob.php`):
   ```php
   $totalFromConfigs = sum(config.usage_bytes + config.settled_usage_bytes);
   $effectiveUsage = max(0, $totalFromConfigs - $reseller->admin_forgiven_bytes);
   $reseller->traffic_used_bytes = $effectiveUsage;
   ```

### C) Benefits

1. **Config Data Intact**: Individual config usage remains visible and accurate
2. **Quota Forgiveness**: Admin can grant fresh quota without losing usage history
3. **Survives Syncs**: The forgiveness persists through all sync job runs
4. **New Traffic Counted**: Traffic after reset is properly tracked
5. **Transparent**: Resellers see actual config usage, admins see forgiven amount

## Example Scenario

**Initial State**:
- Config 1: 1 GB used, 500 MB settled = 1.5 GB total
- Config 2: 1 GB used, 500 MB settled = 1.5 GB total
- Reseller total: 3 GB
- Reseller quota: 10 GB

**Admin Resets**:
- `admin_forgiven_bytes` = 3 GB (snapshot of current total)
- `traffic_used_bytes` = 0
- Configs unchanged (still show 1.5 GB each)

**After Sync Job**:
- Calculates: 3 GB (configs) - 3 GB (forgiven) = 0 GB effective
- `traffic_used_bytes` remains 0
- Reseller has full 10 GB quota available

**New Traffic (500 MB)**:
- Calculates: 3.5 GB (configs) - 3 GB (forgiven) = 0.5 GB effective
- `traffic_used_bytes` = 0.5 GB
- Correctly counts only new traffic

## Testing

Comprehensive tests verify:
- ✅ Dashboard displays all three usage metrics correctly
- ✅ Admin reset leaves config data intact
- ✅ Admin reset survives sync job recalculations
- ✅ New traffic after reset is counted properly
- ✅ Reseller config resets still work (settling usage)
- ✅ Quota enforcement respects forgiven bytes

## Migration

Run migration to add the new field:
```bash
php artisan migrate
```

This adds `admin_forgiven_bytes` column to `resellers` table.

## Backward Compatibility

- Existing resellers will have `admin_forgiven_bytes` = 0 (no forgiveness)
- Existing behavior remains unchanged until admin uses reset
- Config reset behavior (settling) unchanged
- Sync jobs work correctly for both old and new data
