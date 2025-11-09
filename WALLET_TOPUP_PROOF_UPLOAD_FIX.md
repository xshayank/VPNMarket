# Wallet Top-Up Proof Photo Upload Fix

## Overview
This document describes the implementation of proof photo upload functionality for wallet top-ups and the fix for redirect logic based on user type.

## Problem Statement

### Issue 1: Proof Photo Upload
When users attempted to upload a proof photo while submitting a wallet top-up request:
- The upload button didn't work
- Server returned 500 errors
- No proof image was saved
- Admin approval page couldn't display the proof

### Issue 2: Redirect Logic
After submitting a top-up request:
- All users (both resellers and normal users) were redirected to `/dashboard`
- Resellers should be redirected to `/reseller` instead

## Solution

### 1. Database Schema
Added `proof_image_path` column to the `transactions` table:
```php
$table->string('proof_image_path')->nullable()->after('description');
```

### 2. Form Updates
Updated `/wallet/charge` form:
- Added `enctype="multipart/form-data"` to enable file uploads
- Added file upload input field with validation hints
- Updated error display to show all validation errors

### 3. File Upload Handling
In `OrderController::createChargeOrder`:
- Added validation: `'proof' => 'required|image|mimes:jpeg,png,webp,jpg|max:4096'`
- Implemented UUID-based file storage: `wallet-topups/{year}/{month}/{uuid}.{ext}`
- Added try/catch error handling to prevent 500 errors
- Store file path in `proof_image_path` column

### 4. Redirect Logic Fix
Implemented conditional redirect based on user type:
```php
$redirectRoute = ($reseller && method_exists($reseller, 'isWalletBased') && $reseller->isWalletBased())
    ? '/reseller'
    : route('dashboard');
```

### 5. Admin Interface Updates
Enhanced `WalletTopUpTransactionResource`:
- Added `ImageColumn` for thumbnail preview in table
- Added `ViewField` for full image display in form
- Added "مشاهده رسید" (View Proof) action with modal
- Created custom Blade views for image display

## Security Measures

### File Validation
- **Type**: Only image files (jpeg, png, webp, jpg)
- **Size**: Maximum 4MB (4096 KB)
- **Required**: File upload is mandatory

### Storage Security
- Files stored in `storage/app/public/wallet-topups/`
- Path organized by year/month for better management
- UUID-based filenames to prevent conflicts and enumeration
- Public disk ensures proper access control via storage:link

### Error Handling
- Try/catch blocks to prevent 500 errors
- Validation errors returned to user with proper messages
- Safe logging without PII exposure

## File Structure

### New Files Created
```
database/migrations/
  └── 2025_11_09_193128_add_proof_image_path_to_transactions_table.php

resources/views/filament/
  ├── forms/components/
  │   └── proof-image-preview.blade.php
  └── modals/
      └── proof-image.blade.php

public/images/
  └── no-image.png (placeholder)
```

### Modified Files
```
app/Models/Transaction.php
app/Http/Controllers/OrderController.php
app/Filament/Resources/WalletTopUpTransactionResource.php
resources/views/wallet/charge.blade.php
tests/Feature/WalletTopUpTransactionTest.php
```

## Testing

### Test Coverage
16 tests, all passing:
1. ✓ Proof image upload works correctly
2. ✓ File validation (type, size, required) works
3. ✓ Images stored in correct path format
4. ✓ Redirect logic for resellers → /reseller
5. ✓ Redirect logic for users → /dashboard
6. ✓ Transaction creation with proof_image_path
7. ✓ Admin permissions and approval flow
8. ✓ All existing tests still pass

### Test Examples
```php
test('wallet charge submission stores proof image in correct path', function () {
    Storage::fake('public');
    $user = User::factory()->create();
    $file = UploadedFile::fake()->image('receipt.jpg')->size(1024);

    $this->actingAs($user)
        ->post(route('wallet.charge.create'), [
            'amount' => 100000,
            'proof' => $file,
        ])
        ->assertRedirect(route('dashboard'));

    $transaction = Transaction::where('user_id', $user->id)->first();
    Storage::disk('public')->assertExists($transaction->proof_image_path);
});
```

## Deployment Instructions

### 1. Run Migration
```bash
php artisan migrate
```

### 2. Create Storage Link (if not exists)
```bash
php artisan storage:link
```

### 3. Verify Permissions
Ensure the storage directory is writable:
```bash
chmod -R 775 storage
chown -R www-data:www-data storage
```

### 4. Clear Caches
```bash
php artisan cache:clear
php artisan config:clear
php artisan view:clear
php artisan route:clear
```

### 5. Test Upload
1. Log in as a regular user
2. Navigate to /wallet/charge
3. Enter amount and upload a JPEG image
4. Verify redirect to /dashboard
5. Check admin panel for proof image

6. Log in as a reseller
7. Navigate to /wallet/charge
8. Enter amount and upload a JPEG image
9. Verify redirect to /reseller
10. Check admin panel for proof image

## Acceptance Criteria

### ✅ All Met
- [x] Uploading a proof photo on /wallet/charge succeeds (no 500 error)
- [x] File validation works (type: image/*, size: max 4MB)
- [x] Images stored in public disk under wallet-topups/{Y}/{m}/{uuid}.ext
- [x] Proof image visible in admin approval page with thumbnail
- [x] "مشاهده رسید" action opens modal with full image
- [x] Resellers redirect to /reseller after submission
- [x] Normal users redirect to /dashboard after submission
- [x] No 500 errors on invalid file upload (validation errors shown)
- [x] All tests passing

## Known Limitations

1. **Storage Link Required**: The `php artisan storage:link` command must be run to make uploaded images accessible via web
2. **No Image Optimization**: Uploaded images are stored as-is without resizing or compression
3. **No Deletion on Reject**: Rejected transaction images remain in storage (manual cleanup may be needed)

## Future Enhancements

1. **Image Optimization**: Auto-resize images to reduce storage usage
2. **Cleanup Job**: Delete proof images for rejected/old transactions
3. **Multiple Images**: Allow upload of multiple proof images
4. **Direct Camera Upload**: Support direct camera capture on mobile devices
5. **OCR Integration**: Auto-extract amount from receipt images

## Related Documentation
- [WALLET_TOPUP_APPROVAL_IMPLEMENTATION.md](./WALLET_TOPUP_APPROVAL_IMPLEMENTATION.md)
- [PR_SUMMARY_WALLET_TOPUP_APPROVAL.md](./PR_SUMMARY_WALLET_TOPUP_APPROVAL.md)
