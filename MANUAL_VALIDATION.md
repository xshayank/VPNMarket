# Manual Validation Checklist

## Pre-Deployment Testing

### 1. Traffic-Based Reseller Panel Selection
- [ ] Navigate to Admin > Resellers > Create New Reseller
- [ ] Select Type: "ترافیک‌محور" (traffic)
- [ ] Verify Panel selector appears and is required
- [ ] Select a Marzneshin panel
- [ ] Verify Marzneshin services section appears
- [ ] Verify services are loaded from the panel
- [ ] Select multiple services
- [ ] Save reseller
- [ ] Verify panel_id and marzneshin_allowed_service_ids are saved

### 2. Traffic-Based Reseller Config Creation
- [ ] Login as traffic-based reseller
- [ ] Navigate to Configs > Create Config
- [ ] Verify only assigned panel appears (if panel_id is set)
- [ ] Select the panel
- [ ] Verify service selection is limited to whitelisted services
- [ ] Attempt to create config with non-whitelisted service (should fail)
- [ ] Create config with valid service
- [ ] Verify config is created successfully

### 3. User Dropdown in Create Reseller
- [ ] Navigate to Admin > Resellers > Create New Reseller
- [ ] Click on User dropdown
- [ ] Verify users are preloaded and displayed as "Name (Email)"
- [ ] Type a user's name in search box
- [ ] Verify search works and filters by name
- [ ] Type a user's email in search box
- [ ] Verify search works and filters by email
- [ ] Verify users with null names show as "بدون نام (Email)"
- [ ] Select a user and save

### 4. Plan-Based Reseller Quantity
- [ ] Login as plan-based reseller
- [ ] Navigate to Plans > Available Plans
- [ ] Verify quantity field shows default value of 1
- [ ] Verify quantity field has required indicator (*)
- [ ] Try to submit form with quantity = 0 (should fail with validation)
- [ ] Try to submit form without entering quantity (should use default 1)
- [ ] Submit form with quantity = 5
- [ ] Verify order is created with correct quantity

### 5. Allowed Plans Repeater on Edit
- [ ] Navigate to Admin > Resellers > Edit existing plan-based reseller
- [ ] Scroll to "پلن‌های مجاز" (Allowed Plans) section
- [ ] Verify existing allowed plans are displayed
- [ ] Verify plan names show in repeater item headers
- [ ] Click to add a new plan
- [ ] Verify plan dropdown shows reseller-visible plans
- [ ] Select a plan
- [ ] Verify override type and value fields work
- [ ] Try to add same plan twice (should be prevented)
- [ ] Save changes
- [ ] Refresh page and verify changes persisted

### 6. Backward Compatibility
- [ ] Verify existing resellers without panel_id still work
- [ ] Verify existing orders without quantity issues work
- [ ] Verify no existing functionality is broken

## Database Validation

```sql
-- Check panel_id column exists
DESCRIBE resellers;

-- Check default value for quantity
SHOW CREATE TABLE reseller_orders;

-- Check a reseller's panel relationship
SELECT r.id, r.type, r.panel_id, p.name as panel_name 
FROM resellers r 
LEFT JOIN panels p ON r.panel_id = p.id 
LIMIT 5;

-- Check marzneshin_allowed_service_ids storage
SELECT id, type, marzneshin_allowed_service_ids 
FROM resellers 
WHERE type = 'traffic' 
LIMIT 5;

-- Check allowed plans relationship
SELECT rap.*, p.name 
FROM reseller_allowed_plans rap 
JOIN plans p ON rap.plan_id = p.id 
LIMIT 5;
```

## Test Results Expected
- All 4 tests in ResellerResourceTest should pass
- No new security vulnerabilities
- No breaking changes to existing functionality

## Rollback Plan
If issues occur:
```bash
# Rollback migrations
php artisan migrate:rollback --step=2

# Revert code changes
git revert <commit-hash>
```
