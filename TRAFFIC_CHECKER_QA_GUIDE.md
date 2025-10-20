# QA Manual Testing Guide - Traffic Checker and Enforcement Improvements

## Overview
This PR implements improvements to the traffic checker and enforcement system for reseller configs, including:
1. Marzneshin usage sync using `getUser()` API
2. Per-config overrun allowance via settings
3. Rate-limited auto-disable when reseller quota/window expires
4. Automatic re-enabling when reseller recovers
5. Separation of manual vs auto-disabled configs

## Prerequisites
- Working VPNMarket installation
- Access to admin panel
- Access to reseller panel
- At least one Marzneshin or Marzban panel configured
- Database access for verification

## Test Scenarios

### 1. Marzneshin Usage Sync (Fix #1)

**Objective**: Verify that Marzneshin usage is fetched correctly using the new `getUser()` method.

**Steps**:
1. Create a reseller with traffic-based type
2. Create a panel of type Marzneshin with valid credentials
3. Create a reseller config on the Marzneshin panel
4. Use the config to generate some traffic (e.g., 100MB)
5. Manually run the sync job: `php artisan tinker` then `Modules\Reseller\Jobs\SyncResellerUsageJob::dispatch()`
6. Wait for job to complete
7. Check the config's `usage_bytes` field in database
8. Check the reseller's `traffic_used_bytes` field

**Expected Results**:
- Config `usage_bytes` should reflect actual usage from Marzneshin (e.g., ~100MB)
- Reseller `traffic_used_bytes` should be sum of all config usages
- Application logs should show successful usage fetch from Marzneshin

### 2. Panel Resolution (Fix #1)

**Objective**: Verify that the sync job uses the exact `panel_id` stored in the config.

**Steps**:
1. Create two Marzneshin panels (Panel A and Panel B)
2. Create a reseller
3. Create Config 1 on Panel A (store `panel_id = A`)
4. Create Config 2 on Panel B (store `panel_id = B`)
5. Run sync job
6. Check application logs

**Expected Results**:
- Config 1 should query Panel A (not Panel B)
- Config 2 should query Panel B (not Panel A)
- Logs should show correct panel being used for each config

### 3. Allow Config Overrun Setting (Fix #2)

**Objective**: Verify that per-config limits are only enforced when `reseller.allow_config_overrun` is false.

**Test Case A - Overrun Allowed (Default)**:
1. Ensure setting `reseller.allow_config_overrun` doesn't exist or is set to `true` in database
2. Create a config with `traffic_limit_bytes = 1GB`
3. Use 1.5GB of traffic through the config
4. Run sync job
5. Check config status

**Expected Results**:
- Config should remain `active` despite exceeding its own limit
- `usage_bytes` should be updated to 1.5GB

**Test Case B - Overrun Not Allowed**:
1. Set `reseller.allow_config_overrun` to `false` in database: 
   ```sql
   INSERT INTO settings (key, value) VALUES ('reseller.allow_config_overrun', 'false');
   ```
2. Create a config with `traffic_limit_bytes = 1GB`
3. Use 1.5GB of traffic through the config
4. Run sync job
5. Check config status

**Expected Results**:
- Config should be `disabled`
- Should have a `ResellerConfigEvent` with `type = 'auto_disabled'` and `meta->reason = 'traffic_exceeded'`

### 4. Reseller-Level Auto-Disable with Rate Limiting (Fix #3)

**Objective**: Verify rate-limited auto-disable when reseller exceeds quota or window.

**Test Case A - Quota Exhausted**:
1. Create a reseller with `traffic_total_bytes = 5GB`
2. Create 10 active configs under this reseller
3. Use traffic such that total reaches 5GB
4. Run sync job
5. Monitor the time taken for all configs to be disabled

**Expected Results**:
- All 10 configs should be disabled
- Rate limiting should apply: max 3 configs per second
- Each config should have `ResellerConfigEvent` with:
  - `type = 'auto_disabled'`
  - `meta->reason = 'reseller_quota_exhausted'`
  - `meta->remote_success = true/false` (based on remote panel response)
- Logs should show: "Auto-disable completed for reseller X: 10 disabled, 0 failed"

**Test Case B - Window Expired**:
1. Create a reseller with valid quota but `window_ends_at` in the past
2. Create 5 active configs
3. Run sync job

**Expected Results**:
- All configs disabled with `meta->reason = 'reseller_window_expired'`
- Rate limiting applied

### 5. Auto Re-Enable After Recovery (Fix #4)

**Objective**: Verify automatic re-enabling when reseller quota/window is extended.

**Steps**:
1. Create a reseller with `traffic_total_bytes = 5GB`, used = 5GB (exhausted)
2. Create 3 configs that were auto-disabled due to quota exhaustion
3. Update reseller: `traffic_total_bytes = 10GB` (admin recharge)
4. Run re-enable job: `Modules\Reseller\Jobs\ReenableResellerConfigsJob::dispatch()`
5. Check config statuses

**Expected Results**:
- All 3 configs should be re-enabled (`status = 'active'`)
- `disabled_at` should be NULL
- Each should have `ResellerConfigEvent` with:
  - `type = 'auto_enabled'`
  - `meta->reason = 'reseller_recovered'`
  - `meta->remote_success = true/false`
- Logs: "Auto-enable completed for reseller X: 3 enabled, 0 failed"

### 6. Manual vs Auto-Disable Separation (Fix #5)

**Objective**: Verify that manually disabled configs are NOT re-enabled automatically.

**Steps**:
1. Create a reseller with sufficient quota
2. Create 2 configs:
   - Config A: auto-disabled by system (reseller quota exhausted)
   - Config B: manually disabled by user (via UI button)
3. Check events for both configs
4. Increase reseller quota
5. Run re-enable job

**Expected Results**:
- Config A should be re-enabled (last event = `auto_disabled`)
- Config B should remain disabled (last event = `manual_disabled`)
- Only Config A should have new `auto_enabled` event

### 7. Scheduler Integration

**Objective**: Verify that the re-enable job runs automatically every minute.

**Steps**:
1. Check `routes/console.php` for the schedule definition
2. Run scheduler: `php artisan schedule:work`
3. Monitor logs for "Starting reseller config re-enable job"

**Expected Results**:
- Re-enable job should be triggered every minute
- Job should exit quickly if no eligible resellers found

### 8. Error Handling and Logging

**Objective**: Verify robust error handling.

**Test Cases**:
1. **Panel Unreachable**: Set invalid panel URL, run sync
   - Should log warnings but continue with other configs
2. **Authentication Failure**: Set wrong panel credentials
   - Should log error and skip that config
3. **Remote Disable Fails**: Mock panel disable to fail
   - Should still update local status to disabled
   - Should log warning
   - Event should have `remote_failed = true`

**Expected Results**:
- System should not crash on individual config failures
- Comprehensive error logging
- Graceful degradation

## Verification Checklist

After implementing all fixes:
- [ ] All unit tests pass (`php artisan test --testsuite=Unit`)
- [ ] All feature tests pass (`php artisan test --testsuite=Feature`)
- [ ] No regressions in existing reseller functionality
- [ ] Manual testing scenarios completed
- [ ] Event types are correctly recorded in `reseller_config_events` table
- [ ] Rate limiting is observable in logs (time between disables)
- [ ] No N+1 query issues during sync
- [ ] Panel credentials are correctly retrieved via `panel_id`

## Database Verification Queries

```sql
-- Check config events
SELECT rc.id, rc.status, rce.type, rce.meta, rce.created_at
FROM reseller_configs rc
LEFT JOIN reseller_config_events rce ON rc.id = rce.reseller_config_id
WHERE rc.reseller_id = <RESELLER_ID>
ORDER BY rc.id, rce.created_at DESC;

-- Check setting
SELECT * FROM settings WHERE key = 'reseller.allow_config_overrun';

-- Check reseller usage
SELECT id, traffic_total_bytes, traffic_used_bytes, 
       (traffic_total_bytes - traffic_used_bytes) as remaining
FROM resellers WHERE type = 'traffic';
```

## Common Issues and Solutions

### Issue: Usage not updating
- **Check**: Panel credentials are correct
- **Check**: `panel_id` is set on config
- **Check**: Panel API is accessible
- **Solution**: Verify with direct API call

### Issue: Configs not re-enabling
- **Check**: Reseller actually has remaining quota
- **Check**: Window is valid
- **Check**: Last event is `auto_disabled`, not `manual_disabled`
- **Solution**: Create test event manually if needed

### Issue: Rate limiting not working
- **Check**: Multiple configs exist (need at least 4 to see delay)
- **Check**: Logs for timing information
- **Solution**: Increase number of test configs

## Performance Considerations

- Sync job should complete within timeout (600s default)
- Rate limiting adds ~0.33s per config after first 3
- For 100 configs: ~33 seconds for disable/enable operations
- Consider running scheduler with `--quiet` in production
