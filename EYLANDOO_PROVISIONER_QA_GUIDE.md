# Eylandoo Provisioner Implementation - QA and Acceptance Testing Guide

## Overview

This document provides manual QA steps and acceptance criteria to verify the Eylandoo provisioner implementation works correctly in production environments.

## Pre-Test Setup

### 1. Test Environment Requirements

- Active Eylandoo panel with API access
- Test reseller account (traffic-based)
- At least 2 test configs on Eylandoo panel
- Access to application logs
- Database access (for verification)

### 2. Prepare Test Data

```bash
# Access Laravel Tinker
php artisan tinker
```

```php
// Create or identify test reseller
$reseller = App\Models\Reseller::factory()->create([
    'type' => 'traffic',
    'status' => 'active',
    'traffic_total_bytes' => 10 * 1024 * 1024 * 1024, // 10 GB
    'traffic_used_bytes' => 0,
    'window_starts_at' => now(),
    'window_ends_at' => now()->addDays(30),
]);

// Note reseller ID for testing
echo "Test Reseller ID: {$reseller->id}\n";
```

## Test Scenarios

### Scenario 1: Verify Factory Resolves Correct Provisioner

**Objective:** Ensure ProvisionerFactory returns EylandooProvisioner for Eylandoo configs

**Steps:**
1. Open tinker: `php artisan tinker`
2. Run:
```php
$config = App\Models\ResellerConfig::where('panel_type', 'eylandoo')->first();
$provisioner = App\Provisioners\ProvisionerFactory::forConfig($config);
echo get_class($provisioner); // Should output: App\Provisioners\EylandooProvisioner
```

**Expected Result:**
- Output: `App\Provisioners\EylandooProvisioner`

**Pass Criteria:** ✅ Correct provisioner class returned

---

### Scenario 2: Enable Config on Eylandoo Panel

**Objective:** Verify config can be enabled with correct API call

**Steps:**
1. Manually disable a config on Eylandoo panel (via panel UI)
2. Note the username/panel_user_id
3. Update local DB to match:
```php
$config = App\Models\ResellerConfig::where('panel_user_id', 'YOUR_USERNAME')->first();
$config->update(['status' => 'disabled']);
```
4. Enable using provisioner:
```php
$provisioner = new Modules\Reseller\Services\ResellerProvisioner();
$result = $provisioner->enableConfig($config);
print_r($result);
```
5. Check Eylandoo panel UI - user should now be enabled
6. Check logs:
```bash
tail -f storage/logs/laravel.log | grep -i "eylandoo enable"
```

**Expected Result:**
- `$result['success']` = true
- `$result['attempts']` = 1 (if no retries needed)
- `$result['last_error']` = null
- User is enabled in Eylandoo panel UI
- Logs show: "Eylandoo enable user result" with success=true

**Pass Criteria:** ✅ Config enabled successfully on panel and locally

---

### Scenario 3: Idempotent Enable (Already Enabled)

**Objective:** Verify enabling an already-enabled config succeeds without errors

**Steps:**
1. Ensure config is enabled on Eylandoo panel
2. Try to enable again:
```php
$config = App\Models\ResellerConfig::where('panel_user_id', 'YOUR_USERNAME')
    ->where('status', 'active')
    ->first();
    
$provisioner = new Modules\Reseller\Services\ResellerProvisioner();
$result = $provisioner->enableConfig($config);
print_r($result);
```
3. Check logs for "already enabled" message

**Expected Result:**
- `$result['success']` = true
- Logs show: "Eylandoo user {username} is already enabled"
- No toggle API call made (can verify with panel API logs if available)

**Pass Criteria:** ✅ Operation succeeds without making unnecessary API calls

---

### Scenario 4: Disable Config on Eylandoo Panel

**Objective:** Verify config can be disabled correctly

**Steps:**
1. Ensure config is enabled on Eylandoo panel
2. Disable using provisioner:
```php
$config = App\Models\ResellerConfig::where('panel_user_id', 'YOUR_USERNAME')->first();
$provisioner = new Modules\Reseller\Services\ResellerProvisioner();
$result = $provisioner->disableConfig($config);
print_r($result);
```
3. Check Eylandoo panel UI - user should now be disabled

**Expected Result:**
- `$result['success']` = true
- User is disabled in Eylandoo panel UI
- Logs show: "Eylandoo disable user result" with success=true

**Pass Criteria:** ✅ Config disabled successfully on panel

---

### Scenario 5: Handle Missing Credentials Gracefully

**Objective:** Verify clear error message when panel credentials are missing

**Steps:**
1. Create a test panel with missing api_token:
```php
$badPanel = App\Models\Panel::create([
    'name' => 'Broken Eylandoo Panel',
    'url' => 'https://test.example.com',
    'panel_type' => 'eylandoo',
    // Missing api_token
    'is_active' => true,
]);

$config = App\Models\ResellerConfig::factory()->create([
    'panel_id' => $badPanel->id,
    'panel_type' => 'eylandoo',
    'panel_user_id' => 'test_user',
]);

$provisioner = new Modules\Reseller\Services\ResellerProvisioner();
$result = $provisioner->enableConfig($config);
print_r($result);
```
2. Check logs for diagnostic info

**Expected Result:**
- `$result['success']` = false
- `$result['last_error']` contains "credentials"
- Logs show: "Missing Eylandoo credentials" with diagnostic info (has_url, has_api_token)
- No HTTP request attempted (can verify with network logs)

**Pass Criteria:** ✅ Clear error message without attempting invalid API call

**Cleanup:**
```php
$config->delete();
$badPanel->delete();
```

---

### Scenario 6: Retry on Transient Errors

**Objective:** Verify retry logic handles temporary network issues

**Note:** This test is difficult to perform in production. Best tested via automated tests or by temporarily blocking network access.

**Alternative Manual Test:**
1. Use automated test:
```bash
php artisan test tests/Feature/EylandooProvisionerTest.php --filter=retries_on_transient_errors
```

**Expected Result:**
- Test passes, showing 3 attempts were made
- Logs show retry attempts with delays

**Pass Criteria:** ✅ Automated test passes

---

### Scenario 7: Re-Enable Flow After Wallet Top-Up

**Objective:** End-to-end test of the primary use case

**Steps:**

1. **Setup - Create suspended reseller with configs:**
```php
$reseller = App\Models\Reseller::factory()->create([
    'type' => 'traffic',
    'status' => 'suspended',
    'traffic_total_bytes' => 5 * 1024 * 1024 * 1024, // 5 GB
    'traffic_used_bytes' => 6 * 1024 * 1024 * 1024, // Over quota
    'window_starts_at' => now(),
    'window_ends_at' => now()->addDays(30),
]);

// Create config and mark as disabled by suspension
$config = App\Models\ResellerConfig::where('reseller_id', $reseller->id)->first();
$meta = $config->meta ?? [];
$meta['disabled_by_reseller_suspension'] = true;
$meta['disabled_by_reseller_suspension_reason'] = 'reseller_quota_exhausted';
$config->update([
    'status' => 'disabled',
    'meta' => $meta,
]);
```

2. **Simulate wallet top-up / traffic reset:**
```php
$reseller->update([
    'traffic_used_bytes' => 0,
    // Keep status as 'suspended' - job will reactivate
]);
```

3. **Trigger re-enable job:**
```php
Modules\Reseller\Jobs\ReenableResellerConfigsJob::dispatch($reseller->id);
```

4. **Wait 10 seconds for job to process**

5. **Verify results:**
```php
$reseller->refresh();
$config->refresh();

echo "Reseller status: {$reseller->status}\n"; // Should be 'active'
echo "Config status: {$config->status}\n";     // Should be 'active'
echo "Meta marker removed: " . (isset($config->meta['disabled_by_reseller_suspension']) ? 'NO' : 'YES') . "\n"; // Should be YES
```

6. **Check Eylandoo panel:** Config should be enabled

7. **Check audit logs:**
```php
$auditLogs = App\Models\AuditLog::where('target_type', 'config')
    ->where('target_id', $config->id)
    ->where('action', 'config_auto_enabled')
    ->get();
    
foreach ($auditLogs as $log) {
    echo "Action: {$log->action}, Reason: {$log->reason}\n";
}
```

**Expected Result:**
- Reseller status = 'active'
- Config status = 'active'
- Meta marker removed from config
- Config enabled on Eylandoo panel
- AuditLog entry created with action='config_auto_enabled'
- ResellerConfigEvent entry created with type='auto_enabled'

**Pass Criteria:** ✅ Complete re-enable flow works end-to-end

---

## Acceptance Criteria Verification

Use this checklist to verify all acceptance criteria are met:

### Functional Requirements

- [ ] **AC1:** Running wallet re-enable flow for reseller with Eylandoo configs results in proper enable API calls to Eylandoo panel
  - **Test:** Scenario 7 (Re-Enable Flow)
  - **Evidence:** Logs show Eylandoo API calls with correct endpoint

- [ ] **AC2:** Configs become active locally after successful remote enable
  - **Test:** Scenario 2 (Enable Config)
  - **Evidence:** Database shows status='active', panel shows user enabled

- [ ] **AC3:** Errors are logged with adequate context
  - **Test:** All scenarios
  - **Evidence:** Logs include config_id, reseller_id, panel_id, error details

- [ ] **AC4:** Transient HTTP errors are retried
  - **Test:** Scenario 6 (Retry Logic)
  - **Evidence:** Automated test passes, showing 3 attempts

- [ ] **AC5:** Provisioner is provider-specific
  - **Test:** Scenario 1 (Factory Resolution)
  - **Evidence:** Correct provisioner class returned for each panel type

- [ ] **AC6:** Operations are idempotent
  - **Test:** Scenario 3 (Idempotent Enable)
  - **Evidence:** Enabling already-enabled config succeeds

### Non-Functional Requirements

- [ ] **Performance:** Re-enable completes within reasonable time (<30s for 10 configs)
  - **Test:** Scenario 7 with multiple configs
  - **Evidence:** Job completion logs

- [ ] **Security:** API credentials never exposed in logs
  - **Test:** Review all log entries
  - **Evidence:** Only boolean flags (has_api_token: true/false) in logs

- [ ] **Reliability:** All automated tests pass
  - **Command:** `php artisan test tests/Feature/EylandooProvisionerTest.php tests/Feature/ResellerConfigReenableTest.php`
  - **Evidence:** 11 tests, 44 assertions, all passing

- [ ] **Code Quality:** No linting errors
  - **Command:** `./vendor/bin/pint --test app/Provisioners/`
  - **Evidence:** All files pass

## Regression Testing

Run these existing tests to ensure no regressions:

```bash
# Eylandoo-related tests
php artisan test tests/Feature/EylandooPanelTypeTest.php
php artisan test tests/Feature/EylandooNodesTest.php
php artisan test tests/Feature/EylandooUsageMetaPersistenceTest.php

# Reseller re-enable tests
php artisan test tests/Feature/ResellerConfigReenableTest.php

# Provisioner tests
php artisan test tests/Feature/EylandooProvisionerTest.php
```

**Expected:** All tests pass with no failures or errors

## Production Deployment Checklist

Before deploying to production:

- [ ] All QA scenarios passed
- [ ] All acceptance criteria verified
- [ ] All regression tests passed
- [ ] Deployment documentation reviewed
- [ ] Rollback plan understood
- [ ] Monitoring dashboards configured
- [ ] On-call engineer briefed
- [ ] Backup of database taken

## Post-Deployment Monitoring (First 24 Hours)

Monitor these metrics:

1. **Re-enable Success Rate**
   - Target: >95% success rate
   - Alert if: <90%

2. **API Response Times**
   - Target: <2s per enable/disable
   - Alert if: >5s

3. **Error Rate**
   - Target: <5% of operations
   - Alert if: >10%

4. **Retry Frequency**
   - Target: <10% of operations need retries
   - Alert if: >20%

## Sign-Off

**QA Engineer:**
- Name: ___________________
- Date: ___________________
- Signature: _______________

**Release Manager:**
- Name: ___________________
- Date: ___________________
- Signature: _______________

---

## Troubleshooting Quick Reference

### Issue: Config not enabling
1. Check panel credentials: `$panel->getCredentials()`
2. Check panel_user_id: `$config->panel_user_id`
3. Verify user exists on panel (via panel UI)
4. Check logs: `grep "config_id.*{ID}" storage/logs/laravel.log`

### Issue: Retries exhausted
1. Check network connectivity to panel
2. Verify panel is online
3. Check rate limiting on panel side
4. Review error messages in logs

### Issue: Wrong provisioner used
1. Verify panel_type: `$panel->panel_type`
2. Check factory logic: `ProvisionerFactory::forPanelType($type)`
3. Clear config cache: `php artisan config:clear`

## Appendix: Sample Log Entries

### Successful Enable
```
[timestamp] Attempting to re-enable config {"config_id":123,"reseller_id":456,"panel_id":789,"panel_type":"eylandoo"}
[timestamp] Eylandoo enable user result {"config_id":123,"success":true,"base_url":"https://panel.example.com"}
[timestamp] Remote enable result for config 123 {"remote_success":true,"attempts":1,"last_error":null}
[timestamp] Config 123 re-enabled in DB (status set to active, meta flags cleared)
```

### Failed Enable (Missing Credentials)
```
[timestamp] Cannot enable Eylandoo config 123: Missing Eylandoo credentials {"config_id":123,"has_url":true,"has_api_token":false}
```

### Retry Scenario
```
[timestamp] Attempt 1/3 to enable Eylandoo config 123 failed: HTTP 503
[timestamp] Attempt 2/3 to enable Eylandoo config 123 failed: HTTP 503
[timestamp] Eylandoo enable user result {"config_id":123,"success":true} (attempt 3)
```
