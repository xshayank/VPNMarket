# Wallet Charge Submission Fix

## Overview
This document explains the fix for wallet charge submission to ensure pending transactions are immediately visible in the admin approval page.

## Problem
Previously, when users submitted `/wallet/charge`:
1. An Order was created with `plan_id = null` and `status = 'pending'`
2. Admin had to approve the Order first in OrderResource
3. Only then would a Transaction with `type = 'deposit'` be created
4. This meant the transaction wouldn't appear in the admin approval page ("تاییدیه شارژ کیف پول") until after order approval

## Solution
Modified the wallet charge submission flow to create a pending Transaction directly:

### Flow After Fix
1. User submits `/wallet/charge` with amount
2. `OrderController@createChargeOrder` creates a Transaction immediately with:
   - `type = 'deposit'`
   - `status = 'pending'`
   - `user_id` = authenticated user ID
   - `order_id = null`
   - Appropriate description for regular user or wallet reseller
3. Transaction is immediately visible in `WalletTopUpTransactionResource` (admin approval page)
4. Admin can approve or reject the transaction
5. On approval: wallet is credited
6. On rejection: no credit

### Key Changes

#### OrderController.php
```php
public function createChargeOrder(Request $request)
{
    $request->validate([
        'amount' => 'required|integer|min:10000',
    ]);

    $user = Auth::user();
    $reseller = $user->reseller;

    // Determine the description based on user type
    $description = ($reseller && method_exists($reseller, 'isWalletBased') && $reseller->isWalletBased())
        ? 'شارژ کیف پول ریسلر (در انتظار تایید)'
        : 'شارژ کیف پول (در انتظار تایید)';

    // Create pending transaction immediately for admin approval
    $transaction = Transaction::create([
        'user_id' => $user->id,
        'order_id' => null,
        'amount' => $request->amount,
        'type' => Transaction::TYPE_DEPOSIT,
        'status' => Transaction::STATUS_PENDING,
        'description' => $description,
    ]);

    Log::info('Wallet charge transaction created', [
        'transaction_id' => $transaction->id,
        'user_id' => $user->id,
        'amount' => $request->amount,
        'is_reseller_wallet' => $reseller && method_exists($reseller, 'isWalletBased') && $reseller->isWalletBased(),
    ]);

    return redirect()->route('dashboard')->with('status', 'درخواست شارژ کیف پول شما با موفقیت ثبت شد. پس از تایید توسط مدیر، موجودی شما افزایش خواهد یافت.');
}
```

#### WalletTopUpTransactionResource.php
Added structured logging for approval and rejection actions:
- Logs approval with user_id/reseller_id, amount, new_balance
- Logs rejection with transaction_id, user_id, amount

## Testing
Added comprehensive tests:
1. `wallet charge submission creates pending transaction for regular user`
2. `wallet charge submission creates pending transaction for wallet reseller`
3. `wallet charge submission validates minimum amount`

All 12 wallet top-up transaction tests passing ✅

## Validation
- Amount must be an integer (تومان)
- Minimum amount: 10,000 تومان
- User must be authenticated

## Logging
Structured logs are created at:
1. **Submission**: transaction_id, user_id, amount, is_reseller_wallet
2. **Approval**: transaction_id, reseller_id/user_id, amount, new_balance
3. **Rejection**: transaction_id, user_id, amount

## Benefits
1. ✅ Immediate visibility in admin approval page
2. ✅ Simplified flow (one step instead of two)
3. ✅ Better audit trail with structured logging
4. ✅ Consistent status constants across codebase
5. ✅ Proper handling for both regular users and wallet resellers
