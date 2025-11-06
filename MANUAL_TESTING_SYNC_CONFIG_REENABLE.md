# Manual Testing Guide: Synchronous Config Re-enable on Reseller Reactivation

## Overview
This PR implements synchronous config re-enabling when a reseller is reactivated by the `reseller:enforce-time-windows` command. Previously, configs were queued for re-enabling but might not be enabled immediately if queue workers were not available.

## What Changed
1. **Synchronous Execution**: `ReenableResellerConfigsJob` is now run synchronously using `dispatchSync()` instead of `dispatch()`
2. **Inline Fallback**: If the job fails or is unavailable, an inline fallback re-enables configs directly
3. **Enhanced Logging**: Detailed logs show each step of the re-enable process

## Manual Testing Steps

### Prerequisites
1. A traffic-based reseller with suspended status
2. At least one disabled config marked with suspension metadata
3. Access to application logs

### Test Scenario 1: Normal Synchronous Re-enable
**Setup:**
1. Create a traffic-based reseller with:
   - Status: `suspended`
   - `window_ends_at`: Extended to future date (e.g., +30 days)
   - `traffic_used_bytes` < `traffic_total_bytes` (has traffic remaining)

2. Create disabled configs for this reseller with meta fields:
   ```php
   'status' => 'disabled',
   'meta' => [
       'suspended_by_time_window' => true,
       'disabled_by_reseller_id' => <reseller_id>
   ]
   ```

**Execute:**
```bash
php artisan reseller:enforce-time-windows
```

**Expected Results:**
1. Command completes successfully
2. Reseller status changes to `active`
3. Configs status changes to `active`
4. Suspension meta fields are cleared from configs
5. Logs show:
   ```
   Starting synchronous config re-enable for reseller {id}
   Dispatching ReenableResellerConfigsJob (sync) for reseller {id}
   ReenableResellerConfigsJob completed synchronously for reseller {id}
   ```

### Test Scenario 2: Verify Inline Fallback (Edge Case)
This is harder to test manually but can be triggered by temporarily renaming the job class.

**Expected Logs if Fallback Triggers:**
```
ReenableResellerConfigsJob failed for reseller {id}: ...
Falling back to inline config re-enable for reseller {id}
Starting inline config re-enable for reseller {id}
Found {n} configs for inline re-enable for reseller {id}
Inline re-enabling config {config_id} for reseller {id}
Config {config_id} re-enabled (inline) for reseller {id}
```

### Test Scenario 3: Multiple Configs
**Setup:**
1. Same reseller setup as Scenario 1
2. Create 3+ disabled configs with various suspension markers:
   - Config 1: `suspended_by_time_window` = true
   - Config 2: `disabled_by_reseller_suspension` = true
   - Config 3: `disabled_by_reseller_id` = <reseller_id>

**Execute:**
```bash
php artisan reseller:enforce-time-windows
```

**Expected Results:**
1. All 3 configs are re-enabled synchronously
2. Logs show per-config re-enable operations
3. All suspension markers cleared from all configs

## Key Log Messages to Verify

### Success Path:
```
[timestamp] Starting synchronous config re-enable for reseller {id}
[timestamp] Dispatching ReenableResellerConfigsJob (sync) for reseller {id}
[timestamp] Starting reseller config re-enable job
[timestamp] Processing specific reseller
[timestamp] Re-enabling configs for reseller {id}
[timestamp] Attempting to re-enable config {config_id}
[timestamp] Config {config_id} re-enabled in DB
[timestamp] ReenableResellerConfigsJob completed synchronously for reseller {id}
```

### Fallback Path (if job fails):
```
[timestamp] ReenableResellerConfigsJob failed for reseller {id}: {error}
[timestamp] Falling back to inline config re-enable for reseller {id}
[timestamp] Starting inline config re-enable for reseller {id}
[timestamp] Found {n} configs for inline re-enable
[timestamp] Inline re-enabling config {config_id}
[timestamp] Config {config_id} re-enabled (inline) for reseller {id}
[timestamp] Inline config re-enable completed: {n} enabled, {m} failed
```

## Verification Checklist
- [ ] Command completes without errors
- [ ] Reseller status changes from `suspended` to `active`
- [ ] All disabled configs with suspension markers become `active`
- [ ] Suspension meta fields cleared:
  - `suspended_by_time_window`
  - `disabled_by_reseller_suspension`
  - `disabled_by_reseller_suspension_reason`
  - `disabled_by_reseller_suspension_at`
  - `disabled_by_reseller_id`
  - `disabled_at`
- [ ] Logs show synchronous dispatch message
- [ ] Logs show per-config re-enable operations
- [ ] Audit logs created for reseller reactivation
- [ ] Config events created for auto_enabled configs

## Database Queries for Verification

### Check reseller status:
```sql
SELECT id, status, window_ends_at, traffic_used_bytes, traffic_total_bytes 
FROM resellers 
WHERE id = {reseller_id};
```

### Check config status and meta:
```sql
SELECT id, status, disabled_at, meta 
FROM reseller_configs 
WHERE reseller_id = {reseller_id};
```

### Check audit logs:
```sql
SELECT * FROM audit_logs 
WHERE target_type = 'reseller' AND target_id = {reseller_id} 
ORDER BY created_at DESC LIMIT 5;
```

### Check config events:
```sql
SELECT rce.* 
FROM reseller_config_events rce
JOIN reseller_configs rc ON rc.id = rce.reseller_config_id
WHERE rc.reseller_id = {reseller_id}
ORDER BY rce.created_at DESC LIMIT 10;
```

## Success Criteria
✅ Configs are re-enabled immediately when command runs (not queued)
✅ Detailed logs available for troubleshooting
✅ Fallback logic ensures configs enabled even if job system fails
✅ All existing tests continue to pass
✅ New tests verify synchronous behavior
