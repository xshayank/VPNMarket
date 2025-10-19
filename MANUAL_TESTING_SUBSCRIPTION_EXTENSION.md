# Manual Testing Guide - Subscription Extension Feature

## Prerequisites
1. Run migration: `php artisan migrate`
2. Ensure you have at least one panel configured (Marzban or Marzneshin recommended)
3. Create a test plan linked to a panel
4. Create a test user with sufficient balance

## Test Scenarios

### Scenario 1: Extension with ≤3 Days Remaining ✅

**Setup:**
1. Create an order for a normal user with a 30-day plan
2. Manually set `expires_at` to 2 days from now in the database:
   ```sql
   UPDATE orders SET expires_at = DATE_ADD(NOW(), INTERVAL 2 DAY), 
       traffic_limit_bytes = 53687091200, usage_bytes = 10737418240 
   WHERE id = [order_id];
   ```

**Test:**
1. As the same user, purchase the same plan again
2. Process payment via wallet

**Expected Result:**
- ✅ Existing order's `expires_at` extended by 30 days from current expiry
- ✅ `usage_bytes` reset to 0
- ✅ `traffic_limit_bytes` set to plan volume
- ✅ Panel user updated (check panel UI)
- ✅ User receives success message

---

### Scenario 2: Extension When Expired ✅

**Setup:**
1. Create an order for a normal user with a 30-day plan
2. Set `expires_at` to yesterday:
   ```sql
   UPDATE orders SET expires_at = DATE_SUB(NOW(), INTERVAL 1 DAY), 
       traffic_limit_bytes = 53687091200, usage_bytes = 10737418240 
   WHERE id = [order_id];
   ```

**Test:**
1. Purchase the same plan again
2. Process payment via wallet

**Expected Result:**
- ✅ Existing order's `expires_at` set to now + 30 days
- ✅ `usage_bytes` reset to 0
- ✅ `traffic_limit_bytes` set to plan volume
- ✅ Panel user updated (check panel UI)
- ✅ User receives success message

---

### Scenario 3: Extension When Traffic Exhausted ✅

**Setup:**
1. Create an order with 10 days remaining
2. Set traffic to exhausted:
   ```sql
   UPDATE orders SET expires_at = DATE_ADD(NOW(), INTERVAL 10 DAY), 
       traffic_limit_bytes = 53687091200, usage_bytes = 53687091200 
   WHERE id = [order_id];
   ```

**Test:**
1. Purchase the same plan again
2. Process payment via wallet

**Expected Result:**
- ✅ Existing order's `expires_at` set to now + 30 days (reset, not extend)
- ✅ `usage_bytes` reset to 0
- ✅ `traffic_limit_bytes` set to plan volume
- ✅ Panel user updated
- ✅ User receives success message

---

### Scenario 4: Blocked Extension (>3 Days with Traffic) ❌

**Setup:**
1. Create an order with 10 days remaining and available traffic:
   ```sql
   UPDATE orders SET expires_at = DATE_ADD(NOW(), INTERVAL 10 DAY), 
       traffic_limit_bytes = 53687091200, usage_bytes = 10737418240 
   WHERE id = [order_id];
   ```

**Test:**
1. Purchase the same plan again
2. Process payment via wallet

**Expected Result:**
- ❌ Transaction rolled back (user balance restored)
- ❌ Error message shown: "شما در حال حاضر یک اشتراک فعال دارید. تمدید فقط در 3 روز آخر قبل از انقضا یا پس از اتمام ترافیک امکان‌پذیر است."
- ❌ No new order created
- ❌ Existing order unchanged

---

### Scenario 5: Reseller Users (Bypass Logic) ✅

**Setup:**
1. Create a reseller user
2. Create an order for reseller with 2 days remaining

**Test:**
1. As reseller, purchase the same plan again
2. Process payment

**Expected Result:**
- ✅ New config created (NOT extended)
- ✅ Existing config untouched
- ✅ Normal reseller flow maintained

---

### Scenario 6: Admin Approval Flow ✅

**Setup:**
1. Normal user with existing order (2 days remaining)
2. User uploads card payment receipt

**Test:**
1. Admin approves payment via Filament admin panel
2. Click "تایید و اجرا" button

**Expected Result:**
- ✅ Same extension logic applies
- ✅ Panel updated
- ✅ User receives Telegram notification (if configured)
- ✅ Success notification to admin

---

## Database Verification

After each test, verify in the database:

```sql
-- Check order details
SELECT id, user_id, plan_id, status, expires_at, 
       usage_bytes, traffic_limit_bytes, config_details
FROM orders 
WHERE user_id = [test_user_id] 
ORDER BY created_at DESC;

-- Check transactions
SELECT * FROM transactions 
WHERE user_id = [test_user_id] 
ORDER BY created_at DESC;
```

## Panel Verification

### Marzban/Marzneshin
1. Log into panel admin
2. Search for user: `user_[user_id]_order_[order_id]`
3. Verify:
   - Expiry date matches `expires_at`
   - Data limit matches `traffic_limit_bytes`
   - Usage is reset (or check if panel tracks separately)

## Troubleshooting

### Issue: Panel update fails
**Check:**
- Panel credentials are correct
- Panel is accessible from server
- Check logs: `storage/logs/laravel.log`

### Issue: Extension blocked unexpectedly
**Check:**
- `expires_at` value: `SELECT expires_at FROM orders WHERE id = X`
- Current time vs expires_at: Should be ≤ 3 days
- Traffic: `SELECT usage_bytes, traffic_limit_bytes FROM orders WHERE id = X`

### Issue: Tests fail
**Run:**
```bash
php artisan test --filter=SubscriptionExtensionTest
```

Check specific failing test and review error message.

## Clean Up After Testing

```sql
-- Remove test orders
DELETE FROM orders WHERE user_id = [test_user_id];

-- Remove test transactions
DELETE FROM transactions WHERE user_id = [test_user_id];

-- Remove test user
DELETE FROM users WHERE id = [test_user_id];
```

## Notes
- All Persian error messages are in the code
- Extension logic only applies to normal users (non-resellers)
- Panel must support update API (Marzban/Marzneshin do, XUI doesn't)
- Usage tracking is new - old orders will have NULL values (works fine)
