# Eylandoo Max Clients - Manual Testing Guide

This guide provides step-by-step instructions for manually testing the max_clients feature for Eylandoo panels.

## Prerequisites

1. Running VpnMarket application with database configured
2. At least one active Eylandoo panel configured in the system
3. A reseller account with access to the Eylandoo panel
4. Access to the Eylandoo API to verify changes

## Test Scenarios

### 1. Config Creation - Max Clients Field Visibility

**Objective**: Verify that the max_clients field appears only for Eylandoo panels.

**Steps**:
1. Log in as a reseller user
2. Navigate to the config creation page (`/reseller/configs/create`)
3. Select an Eylandoo panel from the dropdown
4. **Expected Result**: The "حداکثر تعداد کلاینت‌های همزمان" (Max clients) field should appear below the "Expires days" field
5. Select a non-Eylandoo panel (Marzban, Marzneshin, or X-UI)
6. **Expected Result**: The max_clients field should be hidden

**Pass Criteria**:
- Field is visible only for Eylandoo panels
- Field has a red asterisk (*) indicating it's required
- Default value is 1

### 2. Config Creation - Default Value

**Objective**: Verify that max_clients defaults to 1 when not explicitly set.

**Steps**:
1. Select an Eylandoo panel
2. Fill in required fields (traffic limit, expires days)
3. Leave max_clients at default value (1)
4. Submit the form
5. Check the database: `SELECT meta FROM reseller_configs ORDER BY id DESC LIMIT 1;`
6. **Expected Result**: The meta field should contain `"max_clients":1`

**Pass Criteria**:
- Config is created successfully
- meta['max_clients'] = 1 in the database
- Eylandoo API receives max_clients = 1 in the create user request

### 3. Config Creation - Custom Value

**Objective**: Verify that custom max_clients values are stored and sent to the API.

**Steps**:
1. Select an Eylandoo panel
2. Fill in required fields
3. Set max_clients to 5
4. Submit the form
5. Check the database meta field
6. Check Eylandoo API logs or user details
7. **Expected Result**: meta['max_clients'] = 5 and API user has max_clients = 5

**Pass Criteria**:
- Config is created with max_clients = 5
- Database meta contains correct value
- Eylandoo API receives correct max_clients value

### 4. Config Creation - Validation

**Objective**: Verify that max_clients validation works correctly.

**Test Cases**:

#### 4a. Invalid Value - Zero
1. Set max_clients to 0
2. Submit the form
3. **Expected Result**: Validation error message appears

#### 4b. Invalid Value - Negative
1. Set max_clients to -1
2. Submit the form
3. **Expected Result**: Validation error message appears

#### 4c. Invalid Value - Non-integer
1. Set max_clients to "abc"
2. Submit the form
3. **Expected Result**: Validation error message appears

**Pass Criteria**:
- All invalid values are rejected with appropriate error messages
- Form data is preserved after validation error

### 5. Config Edit - Field Visibility

**Objective**: Verify that max_clients field appears in edit form for Eylandoo configs.

**Steps**:
1. Create an Eylandoo config with max_clients = 2
2. Navigate to the edit page for this config
3. **Expected Result**: Max clients field should be visible with value = 2

**Pass Criteria**:
- Field is visible for Eylandoo configs only
- Field is pre-filled with the stored value from meta

### 6. Config Edit - Update Value

**Objective**: Verify that updating max_clients works correctly.

**Steps**:
1. Edit an existing Eylandoo config
2. Change max_clients from 2 to 7
3. Submit the form
4. Check database: meta field should show max_clients = 7
5. Check ResellerConfigEvent for the edit event
6. **Expected Result**: Event meta should contain old_max_clients = 2 and new_max_clients = 7
7. Verify the Eylandoo API was called with max_clients = 7

**Pass Criteria**:
- Config meta is updated with new value
- Event log contains both old and new values
- Eylandoo API receives update request with new max_clients
- Audit log records the change

### 7. Config Edit - No Change

**Objective**: Verify behavior when max_clients is not changed.

**Steps**:
1. Edit an Eylandoo config
2. Change traffic limit but leave max_clients unchanged
3. Submit the form
4. **Expected Result**: Standard updateUserLimits is called (not updateUser with max_clients)

**Pass Criteria**:
- Config is updated successfully
- Only traffic/expiry changes are sent to API if max_clients didn't change

### 8. Multi-Panel Reseller - Dynamic Field

**Objective**: Verify JavaScript behavior for resellers with multiple panels.

**Steps**:
1. Create a reseller with access to both Eylandoo and Marzban panels
2. Navigate to config creation
3. Switch between panels
4. **Expected Result**: Max clients field shows/hides dynamically based on panel type

**Pass Criteria**:
- Field appears when Eylandoo panel is selected
- Field disappears when other panel types are selected
- Value resets to 1 when switching away from Eylandoo

### 9. Logging and Debug

**Objective**: Verify that debug logging works when APP_DEBUG=true.

**Steps**:
1. Set APP_DEBUG=true in .env
2. Create an Eylandoo config with max_clients = 4
3. Check laravel.log file
4. **Expected Result**: Log entries should show:
   - "Config creation with max_clients" with correct values
   - reseller_id, panel_id, max_clients values

**Pass Criteria**:
- Debug logs appear when APP_DEBUG=true
- Logs contain relevant information (reseller_id, panel_id, max_clients)
- No sensitive data in logs

### 10. API Integration

**Objective**: Verify that the complete flow works end-to-end with a real Eylandoo panel.

**Steps**:
1. Configure a real Eylandoo panel in the system
2. Create a config with max_clients = 3
3. Log into the Eylandoo admin panel
4. Find the created user
5. **Expected Result**: User should have max_clients = 3 in Eylandoo
6. Update the config to max_clients = 5
7. **Expected Result**: Eylandoo user should now have max_clients = 5

**Pass Criteria**:
- Config creation successfully creates user in Eylandoo with correct max_clients
- Config update successfully updates max_clients in Eylandoo
- No errors or warnings in logs

## Database Verification Queries

```sql
-- Check max_clients in config meta
SELECT id, external_username, panel_type, meta 
FROM reseller_configs 
WHERE panel_type = 'eylandoo' 
ORDER BY id DESC LIMIT 10;

-- Check config events for max_clients changes
SELECT id, reseller_config_id, type, meta 
FROM reseller_config_events 
WHERE type = 'edited' 
ORDER BY id DESC LIMIT 10;

-- Check audit logs
SELECT id, action, target_type, target_id, meta, created_at 
FROM audit_logs 
WHERE action = 'reseller_config_edited' 
ORDER BY id DESC LIMIT 10;
```

## Expected API Calls

### Create User
```json
POST /api/v1/users
{
  "username": "resell_1_cfg_123",
  "activation_type": "fixed_date",
  "data_limit": 10.0,
  "data_limit_unit": "GB",
  "expiry_date_str": "2025-12-07",
  "max_clients": 3
}
```

### Update User (with max_clients change)
```json
PUT /api/v1/users/resell_1_cfg_123
{
  "data_limit": 15.0,
  "data_limit_unit": "GB",
  "expiry_date_str": "2025-12-10",
  "max_clients": 5
}
```

## Troubleshooting

### Issue: Field not appearing
- Check JavaScript console for errors
- Verify panel_type is exactly 'eylandoo'
- Check if showNodesSelector is set in the view

### Issue: Validation errors
- Verify min="1" attribute on input
- Check validation rules in ConfigController
- Ensure value is an integer

### Issue: Value not saving
- Check database table has meta column with JSON type
- Verify meta is in $fillable array
- Check that meta is cast to 'array' in model

### Issue: API not receiving max_clients
- Check provisioner logs
- Verify EylandooService is being used
- Check HTTP fake in tests to ensure payload structure is correct

## Success Criteria Summary

All test scenarios should pass with:
- ✅ Field visibility works correctly
- ✅ Default value is 1
- ✅ Custom values are stored and sent to API
- ✅ Validation rejects invalid values
- ✅ Updates work correctly
- ✅ Logging works when debug is enabled
- ✅ End-to-end flow works with real Eylandoo API
