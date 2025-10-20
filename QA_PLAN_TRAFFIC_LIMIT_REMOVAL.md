# QA Plan: Traffic-Based Config Creation Without Quota Check

## Overview
This change removes the blocking validation that prevented traffic-based resellers from creating configs when the requested traffic limit exceeds their remaining quota. Instead, the system relies on existing reseller-level enforcement to handle quota exhaustion.

## Scope of Changes
1. **Removed**: Blocking validation in `ConfigController@store` (lines 105-109)
   - Previous behavior: Rejected config creation with error "Config traffic limit exceeds your remaining traffic quota."
   - New behavior: Allows config creation regardless of remaining quota

2. **Unchanged**: Existing reseller-level enforcement
   - Usage sync job aggregates usage and suspends reseller when traffic exhausted
   - Auto-disables all configs at 3/sec rate when quota exhausted
   - Re-enable job restores only system-disabled configs after admin recharge
   - Reseller unsuspended after recovery

## Test Scenarios

### 1. Config Creation with Exceeding Traffic Limit
**Objective**: Verify configs can be created even when traffic_limit_gb exceeds remaining quota

**Prerequisites**:
- Traffic-based reseller with 5 GB total quota
- 4 GB already used (1 GB remaining)

**Steps**:
1. Login as the reseller
2. Navigate to Create Config page
3. Select a panel
4. Set traffic_limit_gb to 3 GB (exceeds 1 GB remaining)
5. Set expires_days to 30
6. Submit the form

**Expected Results**:
- Config creation succeeds
- Success message displayed: "Config created successfully"
- Config appears in the configs list with status "active"
- Config has 3 GB traffic limit
- Subscription URL is generated

**Actual Results**: _______________

**Status**: [ ] Pass [ ] Fail

---

### 2. Natural Traffic Exhaustion Triggers Auto-Disable
**Objective**: Verify reseller suspension and config auto-disable when quota naturally exhausted

**Prerequisites**:
- Traffic-based reseller with 5 GB total quota
- 2 active configs each with 3 GB traffic limit (total 6 GB > 5 GB quota)
- Configs are actively used

**Steps**:
1. Allow time for configs to consume traffic naturally
2. Wait for usage sync job to run (or trigger manually if in test environment)
3. Verify total usage exceeds 5 GB quota

**Expected Results**:
- Reseller status changes to "suspended"
- Reseller traffic_used_bytes reflects aggregated usage from all configs
- All active configs are disabled at rate of ~3/sec
- Each config has status "disabled" and disabled_at timestamp
- ResellerConfigEvent created for each config with:
  - type: "auto_disabled"
  - meta.reason: "reseller_quota_exhausted"
  - meta.remote_success: true/false
- Logs show: "Reseller {id} suspended due to quota/window exhaustion"
- Logs show: "Auto-disable completed for reseller {id}: X disabled, Y failed"

**Actual Results**: _______________

**Status**: [ ] Pass [ ] Fail

---

### 3. Admin Recharge and Auto-Recovery
**Objective**: Verify only system-disabled configs are re-enabled after admin increases quota

**Prerequisites**:
- Reseller is suspended (status: "suspended")
- Multiple configs are disabled:
  - Config A: auto-disabled due to reseller_quota_exhausted
  - Config B: auto-disabled due to reseller_quota_exhausted
  - Config C: manually disabled by reseller

**Steps**:
1. Admin increases reseller's traffic_total_bytes (e.g., from 5 GB to 20 GB)
2. Wait for re-enable job to run (or trigger manually)

**Expected Results**:
- Reseller status changes to "active"
- Config A and B are re-enabled (status: "active", disabled_at: null)
- Config C remains disabled (was manually disabled)
- ResellerConfigEvent created for re-enabled configs with:
  - type: "auto_enabled"
  - meta.reason: "reseller_recovered"
  - meta.remote_success: true/false
- Logs show: "Reseller {id} reactivated after recovery"
- Logs show: "Auto-enable completed for reseller {id}: X enabled, Y failed"

**Actual Results**: _______________

**Status**: [ ] Pass [ ] Fail

---

### 4. Window Expiry Behavior (Unchanged)
**Objective**: Verify window expiry triggers same auto-disable behavior as traffic exhaustion

**Prerequisites**:
- Traffic-based reseller with valid quota but expiring window
- window_ends_at is in the past
- Active configs exist

**Steps**:
1. Set reseller's window_ends_at to a past date
2. Wait for usage sync job to run

**Expected Results**:
- Reseller status changes to "suspended"
- All configs are auto-disabled at 3/sec rate
- ResellerConfigEvent created with meta.reason: "reseller_window_expired"
- Same recovery behavior as quota exhaustion when window is extended

**Actual Results**: _______________

**Status**: [ ] Pass [ ] Fail

---

### 5. Config Limit Still Enforced (Unchanged)
**Objective**: Verify config_limit feature still works independently

**Prerequisites**:
- Traffic-based reseller with config_limit set to 3
- Reseller already has 3 configs

**Steps**:
1. Login as the reseller
2. Try to create a 4th config
3. Submit the form

**Expected Results**:
- Config creation fails
- Error message: "Config creation limit reached. Maximum allowed: 3"
- No new config is created

**Actual Results**: _______________

**Status**: [ ] Pass [ ] Fail

---

### 6. Other Validations Still Work
**Objective**: Verify other validations remain intact

**Test Cases**:
1. **Panel selection**: 
   - Reseller with assigned panel_id can only use that panel
   - Error: "You can only use the panel assigned to your account."

2. **Service whitelist**:
   - Marzneshin reseller can only select allowed service_ids
   - Error: "One or more selected services are not allowed for your account."

3. **Input validation**:
   - traffic_limit_gb required and must be numeric, min 0.1
   - expires_days required and must be integer, min 1
   - panel_id must exist in panels table

**Expected Results**:
- All validations still work as before
- Appropriate error messages displayed

**Actual Results**: _______________

**Status**: [ ] Pass [ ] Fail

---

## Additional Protection Layer

**Note**: The `EnsureUserIsReseller` middleware blocks suspended resellers from accessing any reseller routes, including config creation. This means:
- When reseller is suspended (due to quota exhaustion), they cannot access the create config form
- Error message: "Your reseller account has been suspended. Please contact support."
- This provides an extra safety layer on top of the enforcement logic

---

## Edge Cases

### 7. Multiple Configs Exceed Quota at Creation
**Objective**: Verify system handles multiple configs with total limits exceeding quota

**Steps**:
1. Reseller has 10 GB quota
2. Create 5 configs, each with 3 GB limit (total 15 GB > 10 GB)
3. All configs start consuming traffic
4. Usage sync runs

**Expected Results**:
- All configs are created successfully
- When total usage exceeds 10 GB, all configs are auto-disabled
- System handles this gracefully with rate limiting

**Actual Results**: _______________

**Status**: [ ] Pass [ ] Fail

---

### 8. Config with Zero Usage Gets Disabled
**Objective**: Verify configs with no usage are also disabled when reseller quota exhausted

**Steps**:
1. Reseller has 5 GB quota
2. Config A uses 6 GB (exceeds quota)
3. Config B has 0 GB usage
4. Usage sync runs

**Expected Results**:
- Reseller suspended
- Both Config A and Config B are disabled
- Both have auto_disabled events

**Actual Results**: _______________

**Status**: [ ] Pass [ ] Fail

---

## Rate Limiting Verification

### 9. Auto-Disable Rate Limiting
**Objective**: Verify 3/sec rate limiting during auto-disable

**Steps**:
1. Create 10 active configs for a reseller
2. Exhaust reseller quota
3. Trigger usage sync job
4. Monitor disable operations with timestamps

**Expected Results**:
- First 3 configs disabled immediately
- Sleep(1) occurs after every 3rd config
- Approximately 1 second between each batch of 3
- All configs eventually disabled

**Actual Results**: _______________

**Status**: [ ] Pass [ ] Fail

---

## Performance Considerations

### 10. Large Number of Configs
**Objective**: Verify system handles resellers with many configs

**Steps**:
1. Create reseller with 50+ configs
2. Exhaust quota
3. Trigger auto-disable
4. Recharge and trigger re-enable

**Expected Results**:
- All operations complete successfully
- Rate limiting prevents overwhelming the panel APIs
- Job completes within timeout (600 seconds)
- Logs show accurate counts

**Actual Results**: _______________

**Status**: [ ] Pass [ ] Fail

---

## Sign-off

**Tested By**: _______________

**Date**: _______________

**Environment**: [ ] Local [ ] Staging [ ] Production

**Overall Status**: [ ] Pass [ ] Fail

**Notes**:
