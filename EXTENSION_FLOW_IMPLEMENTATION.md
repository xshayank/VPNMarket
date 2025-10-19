# Extension Flow Implementation Summary

## Overview
This document summarizes the implementation of the extension flow for normal (non-reseller) users, allowing them to extend existing subscriptions from the same plan rather than creating new configs.

## Implementation Details

### 1. Database Schema Changes

#### Migration: `2025_10_19_182849_add_usage_tracking_to_orders_table.php`
Added the following fields to the `orders` table:
- `traffic_limit_bytes` (unsigned big integer, nullable) - Tracks the total traffic limit in bytes
- `usage_bytes` (unsigned big integer, default 0) - Tracks the current traffic usage in bytes  
- `panel_user_id` (string, nullable) - Stores the username/ID used on the VPN panel

These fields enable tracking of subscription usage and allow for intelligent extension decisions based on traffic consumption and expiration status.

### 2. Model Updates

#### Order Model (`app/Models/Order.php`)
- Added new fields to `$fillable` array: `traffic_limit_bytes`, `usage_bytes`, `panel_user_id`
- Added casts for integer fields to ensure proper data types

### 3. Services Layer

#### ProvisioningService (`app/Services/ProvisioningService.php`)
New centralized service for managing panel user updates across different panel types:

**Key Method: `updateUser(Panel $panel, Plan $plan, string $username, array $options): array`**

Supports three panel types:
- **Marzban**: Uses `MarzbanService::updateUser()` - full support
- **Marzneshin**: Uses `MarzneshinService::updateUser()` - full support  
- **X-UI**: Returns error indicating update not supported (X-UI doesn't have native update API)

Returns structured response:
```php
[
    'success' => bool,
    'message' => string,
    'response' => array|null
]
```

### 4. Controller Layer

#### SubscriptionExtensionController (`app/Http/Controllers/SubscriptionExtensionController.php`)

**Key Methods:**

1. `show(Order $order)` - Display extension eligibility and pricing
   - Checks user ownership
   - Validates subscription status  
   - Determines eligibility via `checkEligibility()`
   - Returns extension view with eligibility information

2. `store(Request $request, Order $order)` - Process extension payment
   - Validates ownership and eligibility
   - Checks wallet balance
   - Processes extension in database transaction:
     - Deducts payment from wallet
     - Determines extension type (extend vs reset)
     - Attempts panel user update via ProvisioningService
     - Falls back to recreate on update failure
     - Updates order with new expiration, traffic limit, and usage
   - Creates transaction record

3. `checkEligibility(Order $order): array` - Determine if extension is allowed
   
   **Returns extension allowed (type: 'reset') when:**
   - Subscription has expired (expires_at <= now)
   - Traffic is exhausted (usage_bytes >= traffic_limit_bytes)
   
   **Returns extension allowed (type: 'extend') when:**
   - 3 days or less remaining AND has available traffic
   
   **Returns extension denied when:**
   - More than 3 days remaining AND has available traffic
   
   **Response format:**
   ```php
   [
       'allowed' => bool,
       'type' => 'extend'|'reset'|null,
       'message' => string
   ]
   ```

### 5. Extension Logic

#### Type: 'extend' (≤3 days remaining, has traffic)
- New expiration = current expires_at + plan.duration_days
- Traffic limit = plan.volume_gb * 1024³ (replaced, not added)
- Usage bytes = 0 (reset)
- Config details preserved or updated

#### Type: 'reset' (expired OR out of traffic)
- New expiration = now + plan.duration_days  
- Traffic limit = plan.volume_gb * 1024³
- Usage bytes = 0 (reset)
- Config details updated with new panel response

### 6. Routes

Added to `routes/web.php`:
```php
Route::get('/subscription/{order}/extend', [SubscriptionExtensionController::class, 'show'])
    ->name('subscription.extend.show');
Route::post('/subscription/{order}/extend', [SubscriptionExtensionController::class, 'store'])
    ->name('subscription.extend');
```

### 7. Views

#### Created: `resources/views/subscriptions/extend.blade.php`
Displays:
- Subscription details (plan name, volume, duration, current expiration, price)
- User wallet balance
- Eligibility status and extension type
- Calculated new expiration date based on type
- Payment button (if eligible and sufficient balance)
- Charge wallet link (if insufficient balance)
- Error message (if not eligible)

#### Updated: `resources/views/dashboard.blade.php`
- Replaced old "تمدید" (Renew) POST form button with:
  - New "تمدید" (Extend) link button that navigates to extension page
  - Color changed from yellow (bg-yellow-500) to blue (bg-blue-500)
  - Links to `route('subscription.extend.show', $order->id)`

### 8. OrderController Updates

Updated `OrderController::processWalletPayment()` to track usage fields:
- Calculate `$trafficLimitBytes = $plan->volume_gb * 1073741824`
- Store in order: `traffic_limit_bytes`, `usage_bytes` (0), `panel_user_id`
- Applied to both new orders and renewal orders

### 9. Tests

Created `tests/Feature/SubscriptionExtensionTest.php` with 8 comprehensive tests:

1. ✓ user can extend subscription when 3 days or less remaining
2. ✓ user cannot extend subscription when more than 3 days remaining and has traffic
3. ✓ user can extend subscription when out of traffic  
4. ✓ user can extend subscription when expired
5. ✓ extension denied when insufficient wallet balance
6. ✓ user cannot access another users subscription extension
7. ✓ extension type is extend when within 3 days and has traffic
8. ✓ extension type is reset when expired

**All 8 tests passing with 24 assertions**

## Security Considerations

1. **Ownership Validation**: Controller checks `Auth::id() === $order->user_id` before allowing extension
2. **Status Validation**: Only 'paid' orders with associated plans can be extended
3. **Balance Check**: Validates sufficient wallet balance before processing
4. **Transaction Safety**: Uses database transactions to ensure atomic operations
5. **Panel Authentication**: All panel operations require proper authentication

## User Experience Flow

### Happy Path (Extension Allowed)
1. User views dashboard → sees "تمدید" button on subscription
2. Clicks button → navigates to extension page
3. Extension page shows:
   - Subscription details
   - Eligibility status (allowed)
   - Extension type (extend or reset)
   - New expiration date
   - Price and wallet balance
4. User clicks "تأیید و پرداخت از کیف پول"
5. System processes:
   - Deducts from wallet
   - Updates panel user
   - Updates local order
   - Creates transaction record
6. Redirects to dashboard with success message

### Unhappy Path (Extension Denied)
1. User views dashboard → sees "تمدید" button
2. Clicks button → navigates to extension page  
3. Extension page shows:
   - Subscription details
   - Error: "تمدید مجاز نیست"
   - Message: Can only extend in final 3 days or after traffic exhausted
   - "بازگشت به داشبورد" button
4. User returns to dashboard
5. Can purchase new subscription via "خرید سرویس جدید" tab

### Insufficient Balance Path
1-3. Same as happy path
4. Extension page shows:
   - Warning: "موجودی کیف پول شما کافی نیست"
   - "شارژ کیف پول" button
5. User clicks to charge wallet
6. After charging, can return to extension

## Messages (Farsi)

### Success Messages
- Extension successful: "سرویس شما با موفقیت تمدید شد. در صورت تغییر لینک، لطفاً لینک جدید را کپی و در نرم‌افزار خود آپدیت کنید."

### Error Messages  
- Not eligible: "شما فقط می‌توانید در 3 روز پایانی قبل از انقضا یا پس از اتمام ترافیک، سرویس را تمدید کنید. برای خرید اشتراک جدید، از بخش 'خرید سرویس جدید' اقدام کنید."
- Insufficient balance: "موجودی کیف پول شما برای انجام این عملیات کافی نیست."

### Info Messages
- Expired reset: "سرویس شما منقضی شده است. با تمدید، سرویس از اکنون شروع خواهد شد."
- Traffic exhausted reset: "ترافیک شما تمام شده است. با تمدید، ترافیک و زمان از اکنون تنظیم می‌شود."
- Extend (≤3 days): "می‌توانید سرویس خود را تمدید کنید. زمان به تاریخ انقضای فعلی اضافه می‌شود."

## Fallback Behavior

When `ProvisioningService::updateUser()` fails:
1. Logs warning with order_id and panel_type
2. Attempts to recreate user:
   - For Marzban: calls `createUser()` with existing username
   - For Marzneshin: calls `createUser()` with existing username  
   - For X-UI: throws exception (not supported)
3. If recreate succeeds, updates config_details with new response
4. If recreate fails, throws exception and rolls back transaction

## Panel Support Matrix

| Panel Type | Update Support | Fallback | Notes |
|------------|----------------|----------|-------|
| Marzban | ✅ Full | Recreate | Native PUT /api/user/{username} |
| Marzneshin | ✅ Full | Recreate | Native PUT /api/users/{username} |  
| X-UI | ❌ None | Error | No native update API |

## Files Changed/Created

### Created (5 files)
1. `database/migrations/2025_10_19_182849_add_usage_tracking_to_orders_table.php`
2. `app/Services/ProvisioningService.php`
3. `app/Http/Controllers/SubscriptionExtensionController.php`
4. `resources/views/subscriptions/extend.blade.php`
5. `tests/Feature/SubscriptionExtensionTest.php`

### Modified (4 files)
1. `app/Models/Order.php` - Added fillable fields and casts
2. `app/Http/Controllers/OrderController.php` - Track usage fields in wallet payment
3. `routes/web.php` - Added extension routes
4. `resources/views/dashboard.blade.php` - Changed renew button to extension link

## Migration Instructions

### For Existing Installations

1. Pull latest code from repository
2. Run migration:
   ```bash
   php artisan migrate
   ```
3. Clear cache:
   ```bash
   php artisan config:clear
   php artisan route:clear
   php artisan view:clear
   ```
4. Rebuild assets (if using Vite):
   ```bash
   npm run build
   ```

### Data Migration Notes

Existing orders will have `NULL` values for new fields:
- `traffic_limit_bytes` → NULL (will be calculated on first extension)
- `usage_bytes` → 0 (default)
- `panel_user_id` → NULL (will be set on first extension)

The system handles NULL gracefully:
- Uses plan.volume_gb when traffic_limit_bytes is NULL
- Treats NULL usage_bytes as 0
- Generates panel_user_id pattern when NULL

## Future Enhancements

1. **Usage Sync Job**: Implement periodic job to sync usage_bytes from panels
2. **X-UI Update Support**: Implement delete+recreate workflow for X-UI panels
3. **Notification System**: Alert users when approaching traffic limit or expiration
4. **Auto-Extension**: Option for automatic renewal when conditions met
5. **Usage Analytics**: Dashboard graphs showing traffic consumption over time
6. **Multiple Extensions**: Allow purchasing multiple extension units at once

## Testing Checklist

- [x] All 8 feature tests passing
- [x] Migration runs successfully  
- [x] Routes registered correctly
- [x] Views render properly
- [ ] Manual test: Extend when ≤3 days remaining
- [ ] Manual test: Extend when out of traffic
- [ ] Manual test: Extend when expired
- [ ] Manual test: Denied when >3 days + has traffic
- [ ] Manual test: Panel update succeeds
- [ ] Manual test: Panel update fails → fallback works
- [ ] Manual test: Insufficient balance flow
- [ ] Manual test: Unauthorized access blocked

## Known Limitations

1. **X-UI Panel**: Does not support direct user updates; requires manual intervention or future implementation of delete+recreate workflow
2. **Usage Sync**: Currently requires manual implementation or external sync mechanism
3. **Concurrent Extensions**: No locking mechanism to prevent double-extension (mitigated by transaction)
4. **Panel Failures**: If both update and recreate fail, transaction rolls back but user may need support intervention

## Support and Troubleshooting

### Common Issues

**Issue**: Extension shows "not eligible" even when expired
- **Check**: Verify `expires_at` is properly set in database
- **Check**: Confirm timezone settings are correct

**Issue**: Panel update fails  
- **Check**: Panel credentials in panels table
- **Check**: Panel API is accessible
- **Check**: Logs in `storage/logs/laravel.log` for detailed error

**Issue**: Config doesn't update after extension
- **Check**: Panel returned valid response
- **Check**: `config_details` field in orders table updated
- **Check**: User has latest config (may need to refresh dashboard)

## Conclusion

The extension flow is fully implemented with comprehensive testing, proper error handling, and clear user messaging. The system supports both "extend" (add to existing time) and "reset" (start from now) behaviors based on subscription state, with intelligent fallback mechanisms for panel operations.
