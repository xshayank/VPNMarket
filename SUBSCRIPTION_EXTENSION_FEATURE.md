# Subscription Extension Feature

## Overview
This feature allows normal (non-reseller) users to extend their existing subscriptions instead of always creating new configs when purchasing the same plan.

## How It Works

### Extension Eligibility
A user can extend their existing subscription if ONE of these conditions is met:
1. **3 days or less remaining**: The subscription expires in 3 days or less
2. **Expired**: The subscription has already expired
3. **Out of traffic**: The user has used all their traffic (usage_bytes >= traffic_limit_bytes)

### Extension Behavior

#### Case 1: Expired or No Traffic
When the subscription is expired or has no traffic remaining:
- **New Expiry**: Set to `now() + plan.duration_days`
- **Traffic Limit**: Set to `plan.volume_gb * 1024^3` bytes
- **Usage**: Reset to 0 bytes
- **Panel Update**: Call panel update API to sync

#### Case 2: Active but ≤3 Days Remaining
When the subscription is still active but has 3 days or less:
- **New Expiry**: Extend from current expiry: `current_expires_at + plan.duration_days`
- **Traffic Limit**: Set to `plan.volume_gb * 1024^3` bytes (NOT added on top)
- **Usage**: Reset to 0 bytes
- **Panel Update**: Call panel update API to sync

### Blocked Extension
If the user has more than 3 days remaining AND still has traffic:
- Extension is **blocked**
- User sees message: "You can only extend a subscription in the final 3 days before expiry or after your traffic has been exhausted."
- System creates a new order/config instead (or shows validation error)

### Reseller Exemption
Resellers are **exempt** from this logic and always get new configs (existing behavior maintained).

## Database Changes

### Migration
A new migration adds two fields to the `orders` table:
```php
$table->bigInteger('usage_bytes')->default(0);
$table->bigInteger('traffic_limit_bytes')->nullable();
```

### Order Model
New methods:
- `canBeExtended()`: Check if order can be extended
- `isExpiredOrNoTraffic()`: Check if expired or out of traffic

## Code Structure

### ProvisioningService
New service at `app/Services/ProvisioningService.php`:
- `provisionOrExtend()`: Main entry point - decides extend vs new
- `findExtendableOrder()`: Find existing active order for user+plan
- `extendExisting()`: Handle extension logic
- `provisionNew()`: Handle new provisioning (original logic)
- `updateUserOnPanel()`: Update user on panel (Marzban/Marzneshin support)

### OrderController
Updated `processWalletPayment()` to use `ProvisioningService`.

### Filament OrderResource
Updated admin approval flow to use `ProvisioningService`.

## Panel Support

### Marzban
- ✅ Supports `updateUser` API
- ✅ Can update expiry and traffic limits

### Marzneshin
- ✅ Supports `updateUser` API
- ✅ Can update expiry and traffic limits

### X-UI
- ⚠️ Limited update support
- Falls back to create new (documented)

## Testing

Run the feature tests:
```bash
php artisan test --filter=SubscriptionExtensionTest
```

### Test Coverage
1. ✅ Extension allowed when ≤3 days remaining
2. ✅ Extension allowed when expired
3. ✅ Extension allowed when out of traffic
4. ✅ Extension blocked when >3 days remaining with traffic
5. ✅ Pending orders cannot be extended
6. ✅ Provisioning service finds extendable orders
7. ✅ Error message when extension blocked
8. ✅ Resellers bypass extension logic
9. ✅ Usage bytes reset on extension
10. ✅ Field type casting works correctly

## User Experience

### Success Messages
- On extension: "سرویس شما با موفقیت فعال شد." (Your service was successfully activated)

### Error Messages
- When blocked: "شما در حال حاضر یک اشتراک فعال دارید. تمدید فقط در 3 روز آخر قبل از انقضا یا پس از اتمام ترافیک امکان‌پذیر است." 
  (You currently have an active subscription. Extension is only possible in the final 3 days before expiry or after traffic is exhausted.)

## Migration Guide

### Running the Migration
```bash
php artisan migrate
```

### Rollback
```bash
php artisan migrate:rollback
```

This will remove the `usage_bytes` and `traffic_limit_bytes` columns.

## Backwards Compatibility

- ✅ Existing orders continue to work
- ✅ Reseller flows unaffected
- ✅ New orders get traffic tracking automatically
- ✅ Old orders without traffic tracking work normally

## Related PRs
- #14 (type casting fixes)
- #15 (provisioner plan instance)
- #17 (reseller fixes)
- #22 (reseller tickets)
- #23 (ticket reply fix)
- #24 (sync schedule)
- #21 (email center)
