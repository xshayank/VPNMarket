# Manual QA Testing Guide: Reseller Config Comments Feature

## Overview
This guide covers manual testing steps to verify the comment field feature on traffic-based reseller configs and the admin panel usage display improvements.

## Prerequisites

1. Run migrations:
   ```bash
   php artisan migrate
   ```

2. Ensure you have:
   - An admin user
   - A traffic-based reseller user
   - At least one active panel configured

## Test Scenarios

### 1. Database Migration Verification

**Objective**: Verify the comment column was added correctly

```sql
-- Connect to your database and run:
DESCRIBE reseller_configs;
-- OR for MySQL/MariaDB:
SHOW COLUMNS FROM reseller_configs;
```

**Expected Result**:
- Column `comment` exists
- Type: VARCHAR(200)
- Nullable: YES
- Position: After `external_username`

### 2. Reseller Panel - Create Config WITH Comment

**Steps**:
1. Log in as a traffic-based reseller user
2. Navigate to `/reseller/configs`
3. Click "ایجاد کانفیگ جدید" (Create New Config)
4. Fill in the form:
   - Panel: Select any active panel
   - Traffic Limit: 10 GB
   - Validity Period: 30 days
   - **Comment**: "VIP Client - John Doe"
5. Click "ایجاد کانفیگ" (Create Config)

**Expected Results**:
- Config is created successfully
- Success message appears
- Redirected to config list
- Comment field accepts the input
- Max 200 characters enforced by HTML attribute

**Verification**:
- Check config list - comment should appear below username in gray italic text
- Check database:
  ```sql
  SELECT id, external_username, comment FROM reseller_configs ORDER BY id DESC LIMIT 1;
  ```

### 3. Reseller Panel - Create Config WITHOUT Comment

**Steps**:
1. Navigate to `/reseller/configs/create`
2. Fill in the form:
   - Panel: Select any active panel
   - Traffic Limit: 5 GB
   - Validity Period: 7 days
   - **Comment**: Leave empty
3. Submit the form

**Expected Results**:
- Config is created successfully
- No comment displayed in the list
- Comment field in database is NULL

### 4. Reseller Panel - Comment Character Limit

**Steps**:
1. Navigate to `/reseller/configs/create`
2. In the comment field, try to paste/type more than 200 characters
3. Try to submit with exactly 200 characters
4. Try to submit with 201 characters (use browser dev tools to bypass HTML maxlength)

**Expected Results**:
- HTML maxlength stops input at 200 chars in browser
- If bypassed, server validation should reject 201+ characters with validation error
- Exactly 200 characters should be accepted

**Test String** (exactly 200 chars):
```
Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi.
```

### 5. Reseller Panel - Display Comments in List

**Steps**:
1. Create 3-4 configs with different comments:
   - "Production server config"
   - "Test environment"
   - "" (empty)
   - "Client: ABC Corp - Department: IT"
2. Navigate to `/reseller/configs`

**Expected Results**:
- All configs are listed
- Configs with comments show the comment below username in smaller, gray, italic text
- Configs without comments don't show any comment line
- Comments are properly HTML-escaped (test with `<script>alert('xss')</script>`)
- Dark mode: Comments are visible in dark mode

### 6. Admin Panel - View Configs with Comments

**Steps**:
1. Log in as admin
2. Navigate to Admin Panel > Resellers
3. Click on any traffic-based reseller
4. View the "کانفیگ‌های کاربران" (User Configs) tab

**Expected Results**:
- Comments appear as descriptions under the username column
- Only shown when comment is present
- Properly displayed in Filament table

### 7. Admin Panel - Usage Display with Null Values

**Steps**:
1. In Admin Panel > Resellers > (select reseller) > Configs tab
2. Create or view configs with different usage scenarios:
   - usage_bytes = 0
   - usage_bytes = NULL (if exists from old data)
   - usage_bytes = 50% of limit
   - usage_bytes = 90% of limit

**Expected Results**:
- **Format**: Always shows "X.XX / Y.YY GB (Z.Z%)"
- **Zero/Null Usage**: Shows "0.0 / 10.0 GB (0.0%)"
- **Progress Bar Colors**:
  - Green (0-69%): `bg-green-500`
  - Yellow (70-89%): `bg-yellow-500`
  - Red (90-100%): `bg-red-500`
- Progress bar width matches percentage
- No errors or division by zero

**Verification SQL**:
```sql
-- Test with various usage values
UPDATE reseller_configs SET usage_bytes = 0 WHERE id = X;
UPDATE reseller_configs SET usage_bytes = 5368709120 WHERE id = Y; -- 5 GB of 10 GB = 50%
UPDATE reseller_configs SET usage_bytes = 9663676416 WHERE id = Z; -- 9 GB of 10 GB = 90%
```

### 8. Admin Panel - Progress Bar Visual Check

**Objective**: Verify progress bar rendering

**Steps**:
1. View a config with 12% usage (should be green)
2. View a config with 75% usage (should be yellow)
3. View a config with 95% usage (should be red)

**Expected Results**:
- Progress bar appears below the text
- Correct color based on percentage
- Bar width visually matches percentage
- Responsive design works on narrow screens

### 9. Security - XSS Prevention

**Steps**:
1. Create a config with malicious comment:
   ```
   <script>alert('XSS')</script>
   ```
2. View in reseller panel
3. View in admin panel

**Expected Results**:
- Comment is displayed as plain text
- No JavaScript execution
- HTML entities properly escaped: `&lt;script&gt;alert('XSS')&lt;/script&gt;`

### 10. Security - SQL Injection Prevention

**Steps**:
1. Try to create config with comment:
   ```
   '; DROP TABLE reseller_configs; --
   ```

**Expected Results**:
- Comment saved as-is (string)
- No SQL errors
- Table remains intact

### 11. Multi-byte Character Support

**Steps**:
1. Create config with Persian/Arabic/Unicode comment:
   ```
   کاربر ویژه - سرویس پریمیوم 🌟
   ```
2. Create config with emoji comment:
   ```
   VIP 👑 Premium Service ⭐⭐⭐
   ```

**Expected Results**:
- Multi-byte characters counted correctly (200 char limit)
- Display correctly in both panels
- No encoding issues

### 12. Backward Compatibility

**Steps**:
1. View existing configs created before this feature (if any)
2. Check their display in both reseller and admin panels

**Expected Results**:
- Old configs work normally
- No errors for configs with NULL comment
- Edit/disable/delete functions work normally

### 13. Config Actions with Comments

**Steps**:
1. Create a config with a comment
2. Test all actions:
   - Disable
   - Enable
   - Delete
   - Copy subscription URL

**Expected Results**:
- All actions work normally
- Comment is preserved during enable/disable
- Comment appears in all views

### 14. Documentation Verification

**Steps**:
1. Review `docs/RESELLER_FEATURE.md`
2. Check that comment feature is mentioned

**Expected Results**:
- Documentation mentions optional comment field
- Max 200 characters noted
- Both reseller and admin sections updated

## Performance Considerations

1. **Query Performance**: Check if comment field affects query performance
   ```sql
   EXPLAIN SELECT * FROM reseller_configs WHERE reseller_id = 1 ORDER BY id DESC LIMIT 20;
   ```

2. **Index Check**: Verify indexes still work efficiently
   ```sql
   SHOW INDEX FROM reseller_configs;
   ```

## Rollback Testing (Optional)

**Steps**:
1. Backup database
2. Run rollback:
   ```bash
   php artisan migrate:rollback --step=1
   ```
3. Verify comment column is removed
4. Verify existing data is intact
5. Re-run migration to restore

## Test Results Template

```
┌─────────────────────────────────────────────────┬──────────┬──────────┐
│ Test Case                                       │ Status   │ Notes    │
├─────────────────────────────────────────────────┼──────────┼──────────┤
│ 1. Database Migration                           │ [ ]      │          │
│ 2. Create Config WITH Comment                   │ [ ]      │          │
│ 3. Create Config WITHOUT Comment                │ [ ]      │          │
│ 4. Comment Character Limit                      │ [ ]      │          │
│ 5. Display Comments in List                     │ [ ]      │          │
│ 6. Admin Panel - View Comments                  │ [ ]      │          │
│ 7. Admin Panel - Usage with Null               │ [ ]      │          │
│ 8. Admin Panel - Progress Bar                   │ [ ]      │          │
│ 9. Security - XSS Prevention                    │ [ ]      │          │
│ 10. Security - SQL Injection                    │ [ ]      │          │
│ 11. Multi-byte Characters                       │ [ ]      │          │
│ 12. Backward Compatibility                      │ [ ]      │          │
│ 13. Config Actions with Comments                │ [ ]      │          │
│ 14. Documentation                               │ [ ]      │          │
└─────────────────────────────────────────────────┴──────────┴──────────┘

Status: ✓ Pass | ✗ Fail | ~ Partial | - Skip
```

## Known Limitations

1. Comments are not searchable in the current implementation
2. Comment edit functionality not included (must delete and recreate)
3. Comment history not tracked
4. No comment in CSV/JSON exports (future enhancement)

## Troubleshooting

### Issue: Comment field not showing in form
**Solution**: Clear view cache: `php artisan view:clear`

### Issue: Validation errors for comments
**Check**: Server-side validation in ConfigController
**Verify**: Max 200 chars, nullable, string type

### Issue: Progress bar not displaying
**Check**: Filament uses Tailwind CSS classes
**Verify**: `bg-green-500`, `bg-yellow-500`, `bg-red-500` classes exist

### Issue: Comments not showing in admin panel
**Check**: ConfigsRelationManager file changes
**Verify**: `->description()` method on username column

## Sign-off

Tester Name: ___________________  
Date: ___________________  
Environment: ___________________  
Overall Result: ___________________
