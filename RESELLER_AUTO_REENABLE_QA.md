# Reseller Auto-Re-enable Flow - QA Manual Testing Guide

## Overview
This feature automatically re-enables reseller configs that were auto-disabled when a traffic-based reseller ran out of time or traffic, after an admin restores traffic or extends the window.

## Prerequisites
- Admin access to the system
- At least one traffic-based reseller set up with a panel
- Panel credentials configured correctly

## Test Scenarios

### Scenario 1: Traffic Quota Exhaustion and Recovery

#### Setup
1. Create a traffic-based reseller with:
   - Traffic: 10 GB total
   - Window: Valid (e.g., 30 days from now)
   - Status: Active
   - Panel: A configured panel (Marzban/Marzneshin/XUI)

2. Create 2-3 reseller configs for this reseller

3. Simulate traffic exhaustion:
   - Update the reseller's `traffic_used_bytes` to equal `traffic_total_bytes`
   - The system should auto-disable all configs when this happens
   - Verify each config has:
     - Status: disabled
     - A `ResellerConfigEvent` with type='auto_disabled' and meta.reason='reseller_quota_exhausted'

#### Test Steps
1. Navigate to Resellers → Select the exhausted reseller
2. Click "افزایش ترافیک" (Add Traffic) action
3. Add 5 GB of traffic
4. Submit the form

#### Expected Results
- Notification appears: "ترافیک با موفقیت افزایش یافت" (Traffic successfully increased)
- The `ReenableResellerConfigsJob` is dispatched
- Within a few seconds (check logs or job queue):
  - Reseller status changes from 'suspended' to 'active'
  - All auto-disabled configs are re-enabled:
    - Status: active
    - disabled_at: null
  - Each config has a new `ResellerConfigEvent` with:
    - type='auto_enabled'
    - meta.reason='reseller_recovered'
    - meta.remote_success=true (if panel API call succeeded)
- On the remote panel, verify the users are enabled

---

### Scenario 2: Window Expiration and Extension

#### Setup
1. Create a traffic-based reseller with:
   - Traffic: 100 GB total, 20 GB used
   - Window: Expired (e.g., ended yesterday)
   - Status: Suspended
   - Panel: A configured panel

2. Create 2-3 reseller configs for this reseller

3. Simulate window expiration:
   - The system should have auto-disabled all configs
   - Verify each config has:
     - Status: disabled
     - A `ResellerConfigEvent` with type='auto_disabled' and meta.reason='reseller_window_expired'

#### Test Steps
1. Navigate to Resellers → Select the expired reseller
2. Click "تمدید بازه" (Extend Window) action
3. Add 30 days to the window
4. Submit the form

#### Expected Results
- Notification appears: "بازه زمانی با موفقیت تمدید شد" (Window successfully extended)
- The `ReenableResellerConfigsJob` is dispatched
- Within a few seconds:
  - Reseller status changes to 'active'
  - All auto-disabled configs are re-enabled
  - New `auto_enabled` events are created
- On the remote panel, verify the users are enabled

---

### Scenario 3: Manually Disabled Configs Should NOT Re-enable

#### Setup
1. Create a traffic-based reseller (active, with quota and valid window)
2. Create 2 configs:
   - Config A: Auto-disabled (via reseller quota exhaustion)
   - Config B: Manually disabled (via admin action, with event type='manual_disabled')

#### Test Steps
1. Add traffic to the reseller (via "افزایش ترافیک")

#### Expected Results
- Only Config A is re-enabled
- Config B remains disabled
- Config B has NO new `auto_enabled` event

---

### Scenario 4: Remote Panel Failure Handling

#### Setup
1. Create a traffic-based reseller with auto-disabled configs
2. Temporarily break the panel connection (e.g., wrong credentials, panel down)

#### Test Steps
1. Add traffic to restore the reseller
2. Check application logs

#### Expected Results
- Local config status is set to 'active' (despite remote failure)
- `auto_enabled` event is created with `remote_success=false`
- Warning logged: "Failed to enable config {id} on remote panel"
- Summary log shows: "X enabled, Y failed"
- Admin should manually verify and fix remote panel issues

---

### Scenario 5: Rate Limiting (3 configs per second)

#### Setup
1. Create a traffic-based reseller with 10+ auto-disabled configs

#### Test Steps
1. Add traffic to restore the reseller
2. Monitor job execution time and logs

#### Expected Results
- Job processes configs in batches of 3 per second
- Total execution time ≥ (total_configs / 3) seconds
- All configs eventually enabled
- No rate limit errors from panel APIs

---

## Verification Checklist

### Database
- [ ] Reseller status changed from 'suspended' to 'active'
- [ ] Configs status changed from 'disabled' to 'active'
- [ ] Configs `disabled_at` set to null
- [ ] New `reseller_config_events` records created with type='auto_enabled'
- [ ] Events have correct meta data (reason='reseller_recovered', remote_success)

### Remote Panel
- [ ] Users are enabled on Marzban/Marzneshin/XUI panel
- [ ] User status shows as active
- [ ] Subscription URLs work correctly

### Logs
- [ ] Log: "Starting reseller config re-enable job"
- [ ] Log: "Found X eligible resellers for re-enable"
- [ ] Log: "Reseller {id} reactivated after recovery"
- [ ] Log: "Re-enabling X configs for reseller {id}"
- [ ] Log: "Auto-enable completed for reseller {id}: X enabled, Y failed"
- [ ] Log: "Reseller config re-enable job completed"

### Edge Cases
- [ ] Test with no eligible resellers (job exits early)
- [ ] Test with reseller that has no auto-disabled configs
- [ ] Test with multiple resellers being restored simultaneously
- [ ] Test with different panel types (Marzban, Marzneshin, XUI)

---

## Troubleshooting

### Issue: Configs not re-enabling
**Check:**
1. Is the reseller now active with traffic remaining?
2. Is the window valid?
3. Were the configs auto-disabled (not manually)?
4. Check job queue: `php artisan queue:work` or `php artisan queue:listen`
5. Check logs for errors

### Issue: Remote panel API failures
**Check:**
1. Panel credentials are correct
2. Panel is accessible
3. Panel API endpoints are working
4. Check logs for specific API errors

### Issue: Rate limiting causing timeouts
**Solution:**
- Job timeout is set to 600 seconds (10 minutes)
- If processing 1000+ configs, consider increasing timeout or processing in smaller batches

---

## Automated Tests
Run the test suite to verify functionality:

```bash
php artisan test --filter=ResellerAutoReenableTest
```

Expected: All 7 tests pass
