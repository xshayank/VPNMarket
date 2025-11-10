# Wallet Reseller Auto-Disable/Enable Implementation

## Summary

This implementation adds two key improvements for wallet-based resellers:

1. **Auto re-enable configs** that were auto-disabled due to wallet suspension once the admin approves the top-up and the reseller is re-enabled.
2. **Show a clear success response** after submitting a charge request at /wallet/charge.

## Implementation Details

### A) Auto-Disabled Config Tagging and Re-Enable

#### Suspension Flow (Already Existed)
When a wallet-based reseller hits the negative threshold (balance <= -1000 تومان):

1. **Reseller Suspension**
   - Status changed to `suspended_wallet`
   - Audit log created: action `reseller_suspended_wallet`

2. **Config Auto-Disable**
   - All active configs are disabled
   - Meta fields set:
     - `disabled_by_wallet_suspension: true`
     - `disabled_by_reseller_id: <reseller_id>`
     - `disabled_at: <timestamp>`
   - Status changed to `disabled`
   - Config event created: type `auto_disabled`, reason `wallet_balance_exhausted`
   - Audit log created: action `config_auto_disabled`
   - Remote panel: Best-effort disable attempt

**Location**: `app/Console/Commands/ChargeWalletResellersHourly.php::disableResellerConfigs()`

#### Re-Enable Flow (NEW)
When the admin approves a wallet top-up transaction:

1. **Transaction Approval**
   - Transaction status updated to `completed`
   - Wallet balance incremented by charge amount

2. **Reseller Reactivation Check**
   - If reseller status is `suspended_wallet` AND
   - Balance > suspension_threshold (-1000)
   - Then: status changed to `active`

3. **Config Auto-Enable** (NEW)
   - Finds all configs where:
     - `reseller_id` matches
     - `status` = 'disabled'
     - `meta.disabled_by_wallet_suspension` is truthy
   - For each config:
     - Remote panel: Best-effort enable attempt
     - Meta fields cleared:
       - `disabled_by_wallet_suspension`
       - `disabled_by_reseller_id`
       - `disabled_at`
     - Status changed to `active`
     - `disabled_at` set to null
     - Config event created: type `auto_enabled`, reason `wallet_recharged`
     - Audit log created: action `config_auto_enabled`
   - Admin notification shows count of re-enabled configs

**Location**: `app/Filament/Resources/WalletTopUpTransactionResource.php`
- Method: `reenableWalletSuspendedConfigs()`
- Called in: approval action (line 228)

### B) Success Feedback After /wallet/charge

#### Before
- Success message: "درخواست شارژ کیف پول شما با موفقیت ثبت شد. پس از تایید توسط مدیر، موجودی شما افزایش خواهد یافت."
- Reseller dashboard did not display `session('status')` messages

#### After
- Success message: "درخواست شارژ با موفقیت ارسال شد و منتظر تایید است."
- Reseller dashboard now displays `session('status')` messages in green banner

**Locations**:
- `app/Http/Controllers/OrderController.php::createChargeOrder()` (line 146)
- `Modules/Reseller/resources/views/dashboard.blade.php` (lines 22-28)

## Safety Guarantees

### Selective Re-Enable
✅ Only configs with `disabled_by_wallet_suspension` flag are re-enabled
✅ Manually disabled configs remain disabled
✅ Uses same JSON query pattern as traffic-based re-enable for consistency

### Reseller Isolation
✅ Query filtered by `reseller_id` - only affects specific reseller
✅ Each reseller's suspension/reactivation is independent

### Traffic Resellers Unaffected
✅ No changes to traffic-based reseller logic
✅ Wallet logic only executes for resellers with `type = 'wallet'`

### Idempotence
✅ Repeated approvals don't re-enable already-enabled configs
✅ Only disabled configs with wallet suspension flag are targeted

### Best-Effort Remote
✅ Remote panel enable attempted but not required for success
✅ Local DB updated regardless to avoid stuck states
✅ Failures logged but don't block re-enable

### Full Audit Trail
✅ All suspension events logged with reason `wallet_balance_exhausted`
✅ All re-enable events logged with reason `wallet_recharged`
✅ Audit logs created for both actions
✅ Remote operation results included in logs

## Database Schema

### Existing Fields (Reused)
- `reseller_configs.status` - 'active' or 'disabled'
- `reseller_configs.disabled_at` - timestamp of disable
- `reseller_configs.meta` - JSON field for metadata

### Meta Fields Used
```json
{
  "disabled_by_wallet_suspension": true,
  "disabled_by_reseller_id": 123,
  "disabled_at": "2025-11-10T10:30:00+00:00"
}
```

### Events Created
- Type: `auto_disabled`, Reason: `wallet_balance_exhausted`
- Type: `auto_enabled`, Reason: `wallet_recharged`

### Audit Logs Created
- Action: `config_auto_disabled`, Reason: `wallet_balance_exhausted`
- Action: `config_auto_enabled`, Reason: `wallet_recharged`
- Action: `reseller_suspended_wallet`, Reason: `wallet_balance_exhausted`
- Action: `reseller_activated`, Reason: `reseller_recovered` (from traffic flow)

## Testing

### Test Coverage
Created `tests/Feature/WalletResellerConfigAutoEnableTest.php` with 8 tests:

1. ✅ Wallet suspension marks configs with `disabled_by_wallet_suspension` flag
2. ✅ Wallet suspension creates `auto_disabled` event for configs
3. ✅ Wallet suspension creates audit log for config disable
4. ✅ Wallet recharge re-enables auto-disabled configs
5. ✅ Wallet recharge creates `auto_enabled` event for re-enabled configs
6. ✅ Wallet recharge does not re-enable manually disabled configs
7. ✅ Wallet recharge only affects configs of the specific reseller
8. ✅ Traffic-based reseller configs are not affected by wallet logic

All tests passing: 8 passed, 28 assertions

### Security Analysis
- CodeQL scan: **PASSED**
- No security vulnerabilities detected

## User Flow

### Suspension Scenario

1. **Hourly Job Runs** (`reseller:charge-wallet-hourly`)
   - Calculates traffic usage since last snapshot
   - Deducts cost from wallet balance
   - Balance drops to -1500 تومان (below -1000 threshold)

2. **Automatic Suspension**
   - Reseller status → `suspended_wallet`
   - All active configs disabled
   - Each config marked with `disabled_by_wallet_suspension: true`
   - Events and audit logs created

3. **Reseller Notices**
   - Dashboard shows: "معلق (کمبود موجودی)"
   - Accessing /reseller redirects to /wallet/charge
   - Message: "کیف پول شما منفی شده است. لطفاً شارژ کنید."

### Recharge and Re-Enable Scenario

1. **Reseller Submits Top-Up**
   - Visits /wallet/charge
   - Enters amount (e.g., 10,000 تومان)
   - Uploads payment proof
   - Clicks submit

2. **Success Feedback**
   - Redirected to /reseller
   - Green banner shows: "درخواست شارژ با موفقیت ارسال شد و منتظر تایید است."

3. **Admin Approves**
   - Opens Filament admin panel
   - Goes to "تاییدیه شارژ کیف پول"
   - Views pending transaction
   - Clicks "تایید"
   - Confirms approval

4. **Automatic Re-Enable**
   - Balance incremented to 8,500 تومان (was -1500 + 10000)
   - Reseller status → `active`
   - System finds 5 configs with `disabled_by_wallet_suspension`
   - All 5 configs re-enabled on remote panel (best-effort)
   - All 5 configs status → `active` in DB
   - Suspension flags cleared from meta
   - Events and audit logs created
   - Admin notification: "ریسلر به طور خودکار فعال شد. 5 کانفیگ به طور خودکار فعال شد."

5. **Reseller Sees**
   - Dashboard now accessible (no redirect)
   - Status badge: "فعال"
   - All configs showing as active
   - Can create new configs again

## Code Locations

### Modified Files
1. `app/Filament/Resources/WalletTopUpTransactionResource.php`
   - Added `reenableWalletSuspendedConfigs()` method (lines 332-430)
   - Updated approval action to call re-enable (line 228)
   - Added necessary imports (lines 6-9)

2. `app/Http/Controllers/OrderController.php`
   - Updated success message (line 146)

3. `Modules/Reseller/resources/views/dashboard.blade.php`
   - Added `session('status')` display (lines 22-28)

### New Files
1. `tests/Feature/WalletResellerConfigAutoEnableTest.php`
   - 8 comprehensive tests
   - Covers all scenarios

### Existing Files Referenced
1. `app/Console/Commands/ChargeWalletResellersHourly.php`
   - Existing suspension logic (lines 150-258)
   - Already marks configs correctly

2. `app/Models/ResellerConfig.php`
   - Model with meta field support

3. `app/Models/Reseller.php`
   - `isSuspendedWallet()` method
   - `isWalletBased()` method

## Configuration

### Config Values
- `billing.wallet.suspension_threshold` = -1000 (تومان)
- `billing.wallet.price_per_gb` = 780 (تومان)

### Environment
No environment changes required.

## Deployment Notes

### Prerequisites
- Laravel 12.x
- Existing wallet reseller functionality
- Existing traffic reseller auto-disable/enable (for pattern consistency)

### Deployment Steps
1. Pull latest code
2. No database migrations needed (uses existing fields)
3. Clear config cache: `php artisan config:clear`
4. Test in staging environment
5. Deploy to production

### Rollback Plan
If issues arise:
1. Revert the 3 modified files
2. Delete the test file
3. Clear config cache
4. No data cleanup needed (meta fields are additive)

## Manual Testing Checklist

Before deploying to production, manually test:

- [ ] Wallet reseller gets suspended when balance <= -1000
- [ ] Configs are disabled and marked with `disabled_by_wallet_suspension`
- [ ] Submit wallet charge shows success message on /reseller
- [ ] Admin can see pending transaction in Filament
- [ ] Approving transaction credits balance
- [ ] Reseller status changes to active
- [ ] Configs are re-enabled automatically
- [ ] Admin sees notification with count
- [ ] Only wallet-suspended configs are re-enabled
- [ ] Manually disabled configs remain disabled
- [ ] Traffic resellers are unaffected
- [ ] Events and audit logs are created

## Known Limitations

1. **Remote Panel Re-Enable**: Best-effort only
   - If remote panel is down, configs are still marked active in DB
   - Logs will show failure but operation completes
   - Reason: Avoid stuck state where DB and panel are out of sync

2. **Rate Limiting**: 3 operations per second
   - Large resellers with many configs may take time
   - Progress logged for monitoring

3. **Manual Intervention**: If admin manually enables a config
   - It won't be auto-disabled again until next suspension
   - This is by design - respects manual overrides

## Future Enhancements

Potential improvements not in current scope:

1. **Retry Logic**: Add automatic retry for failed remote enables
2. **Batch Operations**: Optimize for resellers with 100+ configs
3. **Notification**: Email/SMS to reseller when configs re-enabled
4. **Dashboard Widget**: Show auto-enable history for reseller
5. **Partial Re-Enable**: Option to re-enable only selected configs

## Support

For questions or issues:
1. Check logs: `storage/logs/laravel.log`
2. Look for: "wallet-suspended configs", "wallet_recharged"
3. Check audit logs in Filament admin panel
4. Review events for specific config

## References

- Similar pattern: `Modules/Reseller/Jobs/ReenableResellerConfigsJob.php` (traffic-based)
- Wallet charging: `app/Console/Commands/ChargeWalletResellersHourly.php`
- Approval flow: `app/Filament/Resources/WalletTopUpTransactionResource.php`
