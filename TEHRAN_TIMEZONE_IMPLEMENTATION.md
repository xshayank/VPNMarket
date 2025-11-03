# Tehran Timezone and Calendar-Day Enforcement Implementation Summary

## Overview
This implementation reworks the time-limit enforcement system to use calendar-day boundaries in the Tehran timezone (`Asia/Tehran`), eliminating time-expiry grace periods. All date-based expiration checks now occur at midnight (00:00) Tehran time.

## Changes Made

### 1. Application Timezone Configuration
- **File**: `config/app.php`
  - Changed `'timezone' => 'UTC'` to `'timezone' => env('APP_TIMEZONE', 'Asia/Tehran')`
- **File**: `.env.example`
  - Added `APP_TIMEZONE=Asia/Tehran`
- **Effect**: All Carbon date operations now default to Tehran timezone

### 2. Model Changes

#### Reseller Model (`app/Models/Reseller.php`)
- **Method**: `isWindowValid()`
  - **Old logic**: `now() <= window_ends_at` (inclusive end)
  - **New logic**: `now() < window_ends_at->copy()->startOfDay()` (exclusive at midnight)
  - **Effect**: Window ending on `2025-11-03` becomes invalid at `2025-11-03 00:00:00` Tehran time

#### ResellerConfig Model (`app/Models/ResellerConfig.php`)
- **Method**: `isExpiredByTime()`
  - **Old logic**: `now() >= expires_at`
  - **New logic**: `now() >= expires_at->copy()->startOfDay()`
  - **Effect**: Config expiring on `2025-11-03` is expired at `2025-11-03 00:00:00` Tehran time

### 3. Job Changes

#### SyncResellerUsageJob (`Modules/Reseller/Jobs/SyncResellerUsageJob.php`)
- **Method**: `getTimeExpiryGraceMinutes()`
  - **Old**: Read from setting `reseller.time_expiry_grace_minutes`
  - **New**: Always returns 0 (grace disabled)
- **Method**: `isExpiredByTimeWithGrace()`
  - **Old**: Applied grace minutes if configured
  - **New**: Uses calendar-day boundaries, grace parameter ignored
  - **Effect**: No delay after midnight; configs/windows expire exactly at 00:00

### 4. Form Data Normalization

#### CreateReseller (`app/Filament/Resources/ResellerResource/Pages/CreateReseller.php`)
- **Method**: `mutateFormDataBeforeCreate()`
  - **Change**: 
    - `window_starts_at` set to `now()->startOfDay()`
    - `window_ends_at` set to `now()->addDays(N)->startOfDay()`
  - **Effect**: Resellers created with `window_days` have dates normalized to midnight

#### EditReseller (`app/Filament/Resources/ResellerResource/Pages/EditReseller.php`)
- **Action**: `extend_window`
  - **Change**: 
    - `window_ends_at` set to `baseDate->copy()->addDays(N)->startOfDay()`
    - `window_starts_at` fallback to `now()->startOfDay()`
  - **Effect**: Window extensions use midnight boundaries

#### ConfigController (`Modules/Reseller/Http/Controllers/ConfigController.php`)
- **Method**: `store()`
  - **Change**: `expiresAt = now()->addDays(N)->startOfDay()`
  - **Effect**: Configs created by resellers have expiration normalized to midnight

### 5. UI Changes

#### ResellerEnforcementSettings (`app/Filament/Pages/ResellerEnforcementSettings.php`)
- **Field**: `reseller.time_expiry_grace_minutes`
  - **Change**: 
    - Disabled field with warning message
    - Label changed to "فرصت Grace زمانی (دقیقه) - غیرفعال"
    - Helper text: "⚠️ این تنظیم غیرفعال است. انقضای زمانی بر اساس مرز روز تقویمی (00:00 تهران) اعمال می‌شود."
  - **Effect**: Users cannot modify this setting; it's informational only

- **Documentation Tab**:
  - Added warning box explaining calendar-day enforcement
  - Updated examples to show midnight cutoff behavior

### 6. Documentation Updates

#### RESELLER_FEATURE.md
- Added "Application Timezone" section
- Updated all references to time expiry to mention calendar-day boundaries
- Added examples:
  - "Config expiring on 2025-11-03 is expired at 2025-11-03 00:00:00 Asia/Tehran"
  - "Reseller window ending on 2025-11-03 becomes invalid at 2025-11-03 00:00:00 Asia/Tehran"
- Clarified that time-expiry grace is not applied (always 0)
- Updated job documentation to explain calendar-day semantics

### 7. Test Coverage

#### New Test Suite: `tests/Feature/ResellerTehranTimezoneTest.php`
8 comprehensive tests covering:

1. **test_config_expires_at_midnight_tehran_time**
   - Verifies config with `expires_at = 2025-11-03 00:00:00` is not expired at 23:59:59 but is expired at 00:00:00

2. **test_config_expires_at_midnight_even_with_time_component**
   - Verifies config with `expires_at = 2025-11-03 14:30:00` still expires at midnight, not at 14:30

3. **test_reseller_window_becomes_invalid_at_midnight_tehran**
   - Verifies window with `window_ends_at = 2025-11-03 00:00:00` is valid at 23:59:59 but invalid at 00:00:00

4. **test_reseller_window_becomes_invalid_at_midnight_even_with_time_component**
   - Verifies window with `window_ends_at = 2025-11-03 14:30:00` becomes invalid at midnight, not at 14:30

5. **test_sync_job_suspends_reseller_at_midnight_when_window_expires**
   - Verifies SyncResellerUsageJob suspends reseller and disables configs exactly at midnight
   - Checks audit logs for `reseller_suspended` and `config_auto_disabled` with reason `reseller_window_expired`

6. **test_no_time_expiry_grace_applied**
   - Verifies configs are disabled at midnight with no grace period
   - Confirms config expires at 00:00:00, not later

7. **test_reseller_created_with_window_days_uses_startofday**
   - Verifies CreateReseller normalizes dates to midnight when using `window_days`

8. **test_config_created_with_expires_days_uses_startofday**
   - Verifies ConfigController normalizes `expires_at` to midnight

## Calendar-Day Semantics

### For Configs
```php
// Old behavior
$isExpired = now() >= $config->expires_at; // Could expire at any time

// New behavior
$isExpired = now() >= $config->expires_at->copy()->startOfDay(); // Expires at 00:00 only
```

**Example**: Config with `expires_at = '2025-11-03 14:30:00'`
- Old: Expires at 2025-11-03 14:30:00
- New: Expires at 2025-11-03 00:00:00 (midnight)

### For Reseller Windows
```php
// Old behavior
$isValid = now() <= $reseller->window_ends_at; // Valid until end time (inclusive)

// New behavior
$isValid = now() < $reseller->window_ends_at->copy()->startOfDay(); // Invalid at 00:00 (exclusive)
```

**Example**: Window with `window_ends_at = '2025-11-03 14:30:00'`
- Old: Valid until 2025-11-03 14:30:00 (inclusive)
- New: Invalid starting 2025-11-03 00:00:00 (exclusive)

## Migration Path

### Existing Data
- No database migration needed
- Existing timestamps will be interpreted with new semantics
- Any configs/windows with times other than 00:00 will now expire/end at the start of that day

### Grace Period Transition
- Old: `time_expiry_grace_minutes` could be set to 30, 60, etc.
- New: Setting is ignored (always treated as 0)
- UI shows field as disabled with explanation

## Testing Results

All reseller-related backend tests pass:
- ✅ 8 new tests in ResellerTehranTimezoneTest.php
- ✅ 6 tests in ResellerUsageSyncTest.php
- ✅ 7 tests in ResellerWindowDaysTest.php
- ✅ 9 tests in ResellerConfigLimitTest.php
- ✅ 6 tests in ResellerManualEnableControllerTest.php

**Total**: 36 tests, 110 assertions, all passing

## Acceptance Criteria Verification

✅ **App uses Asia/Tehran timezone for date operations**
- Configured in `config/app.php` and `.env.example`

✅ **Configs expire exactly at start of their end date (00:00 Tehran)**
- Implemented in `ResellerConfig::isExpiredByTime()`
- Verified by tests

✅ **Reseller windows become invalid at 00:00 Tehran on the end date**
- Implemented in `Reseller::isWindowValid()`
- Verified by tests

✅ **Auto-suspension and auto-disable run with correct audit logs**
- `SyncResellerUsageJob` creates:
  - `AuditLog` with action `reseller_suspended`, reason `reseller_window_expired`
  - `AuditLog` with action `config_auto_disabled`, reason `reseller_window_expired`
  - `ResellerConfigEvent` with type `auto_disabled`
- Verified by test: `test_sync_job_suspends_reseller_at_midnight_when_window_expires`

✅ **Time-expiry grace is not applied anywhere (effectively zero)**
- `getTimeExpiryGraceMinutes()` returns 0
- `isExpiredByTimeWithGrace()` ignores grace parameter
- Verified by test: `test_no_time_expiry_grace_applied`

✅ **All existing traffic/quota enforcement remains intact**
- No changes to traffic grace logic
- All existing reseller tests pass

## Backward Compatibility

### Data
- ✅ No breaking changes to database schema
- ✅ Existing timestamps work with new logic
- ⚠️ Behavior change: times other than 00:00 now expire at start of day

### API/Behavior
- ✅ All model methods maintain same signatures
- ✅ Job interfaces unchanged
- ⚠️ Expiration timing changed (documented, intentional)

### Configuration
- ✅ `reseller.time_expiry_grace_minutes` setting still exists (for backward compat)
- ℹ️ Setting is now ignored (always treated as 0)
- ℹ️ UI shows it as disabled

## Files Changed

1. `config/app.php` - Set timezone to Asia/Tehran
2. `.env.example` - Add APP_TIMEZONE
3. `app/Models/Reseller.php` - Update isWindowValid()
4. `app/Models/ResellerConfig.php` - Update isExpiredByTime()
5. `Modules/Reseller/Jobs/SyncResellerUsageJob.php` - Remove grace, use calendar-day
6. `app/Filament/Resources/ResellerResource/Pages/CreateReseller.php` - Normalize dates
7. `app/Filament/Resources/ResellerResource/Pages/EditReseller.php` - Normalize dates
8. `Modules/Reseller/Http/Controllers/ConfigController.php` - Normalize dates
9. `app/Filament/Pages/ResellerEnforcementSettings.php` - Disable grace field, add docs
10. `RESELLER_FEATURE.md` - Update documentation
11. `tests/Feature/ResellerTehranTimezoneTest.php` - Add comprehensive test suite

## Deployment Notes

1. **No database migration required**
2. **Environment variable**: Add `APP_TIMEZONE=Asia/Tehran` to `.env` (optional, defaults in config)
3. **Cache clear**: Run `php artisan config:clear` after deployment
4. **Timezone consideration**: Server timezone doesn't matter; Carbon uses configured timezone
5. **Testing**: Verify cron jobs run correctly with new timezone

## User Impact

### Positive
- ✅ Predictable expiration times (always midnight)
- ✅ Consistent with calendar-day billing/accounting
- ✅ No unexpected grace periods
- ✅ Simpler to understand (end date is end date)

### Changes to Note
- ⚠️ Configs/windows expire at midnight, not at creation time
- ⚠️ No grace period after expiration time
- ℹ️ All times in Tehran timezone (Asia/Tehran)

## Security Considerations

- ✅ No new security vulnerabilities introduced
- ✅ Audit logging remains comprehensive
- ✅ No changes to authentication/authorization
- ✅ Timezone handling is secure (no user input)
