# Marzneshin Renewal Fix - Implementation Summary

## Problem Statement
When extending a subscription on Marzneshin, renewal was failing with API response:
```json
{"detail":{"username":"Field required"}}
```

Additionally, the wallet payment was aborting because the OrderController renewal logic expected a response array with `subscription_url`/`username` from the update endpoint, which update endpoints typically don't return.

## Root Causes
1. **MarzneshinService::updateUser()** was not including `username` field in the PUT request body, which the Marzneshin API requires
2. **OrderController** treated update/create responses the same way, expecting both to return arrays with subscription URLs
3. The renewal logic tried to regenerate subscription links from update responses that don't contain the necessary data

## Changes Made

### 1. app/Services/MarzneshinService.php
**Change:** Added `username` to the PUT request body in `updateUser()` method

**Before:**
```php
$apiData = [];

// Only add fields that are provided
if (isset($userData['expire'])) {
    $apiData['expire_strategy'] = 'fixed_date';
    $apiData['expire_date'] = $this->convertTimestampToIso8601($userData['expire']);
}
```

**After:**
```php
// Always include username in the payload as required by Marzneshin API
$apiData = [
    'username' => $username,
];

// Only add fields that are provided
if (isset($userData['expire'])) {
    $apiData['expire_strategy'] = 'fixed_date';
    $apiData['expire_date'] = $this->convertTimestampToIso8601($userData['expire']);
}
```

**Impact:** 
- The `username` field is now always included in PUT requests
- Existing ISO8601 conversion and conditional field includes remain unchanged
- Return type remains boolean (`true` on success, `false` on failure)

### 2. app/Http/Controllers/OrderController.php
**Change:** Separated renewal and new user creation logic for Marzneshin

**Before:**
```php
$response = $isRenewal
    ? $marzneshinService->updateUser($uniqueUsername, $userData)
    : $marzneshinService->createUser(array_merge($userData, ['username' => $uniqueUsername]));

if ($response && (isset($response['subscription_url']) || isset($response['username']))) {
    $finalConfig = $marzneshinService->generateSubscriptionLink($response);
    $success = true;
}
```

**After:**
```php
if ($isRenewal) {
    // For renewal, updateUser returns boolean
    $updateSuccess = $marzneshinService->updateUser($uniqueUsername, $userData);
    if ($updateSuccess) {
        // Keep existing config_details, just extend expiry
        $originalOrder = Order::find($order->renews_order_id);
        $finalConfig = $originalOrder->config_details;
        $success = true;
    } else {
        throw new \Exception('خطا در تمدید سرویس Marzneshin.');
    }
} else {
    // For new user, createUser returns array with subscription_url
    $response = $marzneshinService->createUser(array_merge($userData, ['username' => $uniqueUsername]));
    if ($response && (isset($response['subscription_url']) || isset($response['username']))) {
        $finalConfig = $marzneshinService->generateSubscriptionLink($response);
        $success = true;
    }
}
```

**Impact:**
- Renewal now correctly handles boolean response from `updateUser()`
- Existing `config_details` (subscription link) is preserved during renewal
- Only `expires_at` timestamp is extended
- New user creation flow remains unchanged
- Specific error message for Marzneshin renewal failures

### 3. tests/Unit/MarzneshinServiceTest.php
**Changes:** Updated 4 test cases to expect boolean return type instead of array

Tests updated:
- `updateUser sends correct API request` - Now asserts `toBeTrue()` and checks for `username` in PUT body
- `updateUser works without service_ids` - Now asserts `toBeTrue()` and verifies `username` is included
- `updateUser returns false on authentication failure` - Changed from `toBeNull()` to `toBeFalse()`
- `updateUser handles exceptions gracefully` - Changed from `toBeNull()` to `toBeFalse()`

### 4. tests/Unit/MarzneshinRenewalTest.php (New File)
**Addition:** Created comprehensive test suite for the renewal scenario

New tests:
- Verifies `username` is included in PUT request body
- Confirms `updateUser` returns boolean, not array
- Tests error scenarios return `false`
- Validates all required fields in PUT body
- Simulates realistic renewal scenario where API doesn't return `subscription_url`

## Security Analysis

### Potential Security Issues Reviewed
1. **SQL Injection:** Not applicable - uses Laravel Eloquent ORM with proper model methods
2. **XSS:** Not applicable - no HTML/JavaScript generation in changed code
3. **Authentication:** Unchanged - still uses existing authentication flow
4. **Authorization:** Unchanged - still validates order ownership
5. **Input Validation:** 
   - `username` parameter is type-hinted as `string`
   - Already validated in the calling code
   - Used in URL path already, no new exposure

### Security Verdict
✅ **No new security vulnerabilities introduced**
- Changes are minimal and focused
- No new user input sources
- Maintains existing security boundaries
- Uses framework-provided protections (Eloquent ORM, type hints)

## Testing Results

### Unit Tests
- All 27 existing MarzneshinService tests: ✅ PASS
- All 5 new MarzneshinRenewalTest tests: ✅ PASS
- Total: 32/32 tests passing

### Code Quality
- Laravel Pint (code style): ✅ PASS (2 files auto-fixed)
- No syntax errors
- Follows existing code patterns

## Regression Impact

### Services NOT Affected
- ✅ **Marzban**: Uses different service class, unaffected
- ✅ **X-UI**: Uses different service class, unaffected
- ✅ **New Marzneshin subscriptions**: Logic unchanged
- ✅ **Wallet charging**: Different code path
- ✅ **Card payments**: Different code path

### Services Affected (Improved)
- ✅ **Marzneshin renewals via wallet**: Now works correctly

## Expected Behavior After Fix

### Renewal Flow (Marzneshin)
1. User clicks "Renew" on expired/expiring subscription
2. Wallet payment is initiated
3. `MarzneshinService::updateUser()` is called with username included in body
4. Marzneshin API successfully updates the user
5. Original subscription link (config_details) is preserved
6. Only expires_at timestamp is extended
7. Order marked as paid
8. User receives success notification

### New Subscription Flow (Marzneshin)
1. User purchases new plan
2. Wallet payment is initiated
3. `MarzneshinService::createUser()` is called
4. Marzneshin API creates new user and returns subscription_url
5. Subscription link is generated and stored
6. Order marked as paid with new config_details

## QA/Testing Recommendations

### Manual Testing Steps
1. **Test Renewal:**
   - Create a paid order on a Marzneshin plan
   - Wait for or manually set expiration
   - Renew via wallet payment
   - Verify: No "username required" error
   - Verify: Order marked as paid
   - Verify: expires_at extended by plan duration
   - Verify: config_details (subscription link) unchanged

2. **Test New Order:**
   - Create new order on Marzneshin plan (non-renewal)
   - Pay via wallet
   - Verify: createUser returns subscription_url
   - Verify: config_details is populated with subscription link

3. **Test Other Panels:**
   - Verify Marzban renewals still work
   - Verify X-UI new orders still work (renewals not supported)

## Files Changed
- `app/Services/MarzneshinService.php` - Added username to PUT body
- `app/Http/Controllers/OrderController.php` - Separated renewal/create logic for Marzneshin
- `tests/Unit/MarzneshinServiceTest.php` - Updated to expect boolean return type
- `tests/Unit/MarzneshinRenewalTest.php` - New comprehensive test suite

## Deployment Notes
- No database migrations required
- No configuration changes required
- No breaking API changes
- Safe to deploy without downtime
