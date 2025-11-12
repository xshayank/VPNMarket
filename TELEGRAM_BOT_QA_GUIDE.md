# Telegram Bot QA Testing Guide

This guide provides step-by-step instructions for testing the new Telegram bot features.

## Prerequisites

- Access to Telegram bot (test environment)
- Admin access to Filament panel
- Database access (optional, for verification)

## Test Cases

### TC-001: Onboarding - New User Registration

**Objective:** Verify new users can create accounts via Telegram

**Steps:**
1. Open Telegram and find the bot
2. Send `/start` command
3. Verify bot responds with welcome message asking for email
4. Enter valid email: `qa-test-{timestamp}@example.com`
5. Verify bot confirms email and asks for password
6. Enter password: `TestPass123!`
7. Verify bot asks for password confirmation
8. Enter same password: `TestPass123!`
9. Verify bot shows success message with account details
10. Verify main menu is displayed

**Expected Results:**
- Email validation works (rejects invalid emails)
- Password minimum length enforced (8 characters)
- Passwords must match
- User created in database with hashed password
- `user_telegram_links` record created
- Telegram session cleared
- No passwords in logs

**Verification:**
```sql
SELECT * FROM users WHERE email LIKE 'qa-test-%' ORDER BY created_at DESC LIMIT 1;
SELECT * FROM user_telegram_links WHERE user_id = <user_id>;
```

---

### TC-002: Onboarding - Duplicate Email

**Objective:** Verify system prevents duplicate email registration

**Steps:**
1. Send `/start` to bot
2. Enter email that already exists: `existing@example.com`
3. Verify bot shows error message
4. Verify user is prompted to try different email

**Expected Results:**
- Duplicate email rejected
- Helpful error message shown
- No user created
- Can retry with different email

---

### TC-003: Wallet Top-up - Card-to-Card

**Objective:** Test card-to-card payment flow

**Steps:**
1. Login to bot (onboarded user)
2. Send `/start` to show main menu
3. Click "💰 Wallet" button
4. Verify current balance is shown
5. Click "💳 Top-up Wallet"
6. Verify payment methods are listed
7. Click "کارت به کارت"
8. Verify bot asks for amount
9. Enter `50000`
10. Verify bot shows card details
11. Take screenshot or photo of any receipt
12. Send photo to bot
13. Verify bot confirms receipt received
14. Go to Filament → "تاییدیه شارژ کیف پول"
15. Find the pending transaction
16. Click "View" to see proof image
17. Click "Approve"
18. Return to Telegram
19. Verify notification received with new balance

**Expected Results:**
- Amount validation (minimum 10,000)
- Card details displayed correctly
- Photo uploaded successfully
- Transaction created with status `pending`
- Proof image saved and visible in Filament
- After approval:
  - Balance updated
  - Telegram notification received
  - Transaction status = `completed`

**Verification:**
```sql
SELECT * FROM transactions 
WHERE user_id = <user_id> 
  AND type = 'deposit' 
ORDER BY created_at DESC LIMIT 1;
```

---

### TC-004: Wallet Top-up - Amount Validation

**Objective:** Verify amount validation rules

**Steps:**
1. Start top-up flow (Card-to-Card)
2. Test invalid amounts:
   - `-1000` (negative)
   - `100` (too small)
   - `abc` (non-numeric)
   - `9999` (below minimum)
3. For each, verify error message shown
4. Enter valid amount: `50000`
5. Verify flow continues

**Expected Results:**
- All invalid amounts rejected with clear error messages
- Valid amounts accepted
- Minimum: 10,000 تومان

---

### TC-005: Reseller Auto-upgrade

**Objective:** Test automatic reseller promotion

**Steps:**
1. Create new user via bot (or use existing with balance < 100,000)
2. Note current balance
3. Top up wallet to reach 100,000 تومان total:
   - If balance is 30,000, top up 70,000
   - If balance is 0, top up 100,000
4. Complete payment (upload proof)
5. Admin approves transaction
6. Check Telegram for notification
7. Verify notification includes upgrade message
8. Send `/start` to show main menu
9. Verify reseller options are now visible:
   - "🎖 Reseller Dashboard"
   - "⚙️ My Configs"
10. Click "🎖 Reseller Dashboard"
11. Verify dashboard shows reseller status

**Expected Results:**
- User upgraded when balance ≥ 100,000 تومان
- Notification includes upgrade confirmation
- Reseller record created with:
  - type: `wallet`
  - status: `active`
  - wallet_balance: transferred from user balance
- User balance becomes 0
- Reseller wallet shows full amount
- Main menu shows reseller features

**Verification:**
```sql
SELECT * FROM resellers WHERE user_id = <user_id>;
SELECT balance FROM users WHERE id = <user_id>;
```

---

### TC-006: Reseller - Dashboard Access

**Objective:** Verify reseller-only features are protected

**Steps:**
1. As non-reseller user, try accessing dashboard
2. Send callback or command (if exposed)
3. Verify access denied or feature not visible
4. As reseller, access dashboard
5. Verify all stats displayed correctly

**Expected Results:**
- Non-resellers cannot access reseller features
- Resellers see:
  - Wallet balance
  - Number of configs
  - Active config count
  - Status

---

### TC-007: Config List Display

**Objective:** Test configuration listing

**Steps:**
1. As reseller with existing configs
2. Click "⚙️ My Configs"
3. Verify configs are listed (max 10)
4. Check each config shows:
   - Status icon (✅/❌)
   - Name
   - Panel name
   - Config ID
5. If no configs, verify appropriate message

**Expected Results:**
- Configs displayed correctly
- Most recent 10 shown
- Status accurately reflected
- Empty state message if no configs

---

### TC-008: Transaction History

**Objective:** Test transaction listing

**Steps:**
1. Login to bot
2. Click "💰 Wallet"
3. Click "📜 Transaction History"
4. Verify last 5 transactions shown
5. Check each transaction shows:
   - Status icon (✅/⏳)
   - Type (💰/🛒)
   - Amount
   - Date

**Expected Results:**
- Last 5 transactions displayed
- Sorted by date (newest first)
- Status icons correct
- Empty message if no transactions

---

### TC-009: My Account Display

**Objective:** Verify account information display

**Steps:**
1. Login to bot
2. Click "👤 My Account"
3. Verify information shown:
   - Email
   - Name
   - Telegram connection status
   - Balance (or reseller balance)
   - Reseller status (if applicable)

**Expected Results:**
- All information accurate
- Telegram username shown if set
- Reseller info displayed if applicable

---

### TC-010: Payment Method Toggle

**Objective:** Verify payment methods respect admin settings

**Steps:**
1. Admin: Go to Theme Settings
2. Disable "Card-to-Card" payment
3. User: Start top-up flow
4. Verify "کارت به کارت" is NOT listed
5. Admin: Re-enable "Card-to-Card"
6. User: Start top-up flow again
7. Verify "کارت به کارت" IS listed

**Expected Results:**
- Only enabled payment methods shown
- Disabled methods completely hidden
- Real-time respect of admin toggles

---

### TC-011: Rate Limiting

**Objective:** Test rate limiting protection

**Steps:**
1. Send rapid commands to bot:
   - `/start` (repeat 10 times quickly)
2. Verify rate limit kicks in
3. Wait 10 seconds
4. Send `/start` again
5. Verify bot responds

**Expected Results:**
- After 5 requests in 10 seconds, further requests ignored
- No error message sent to user
- Rate limit resets after window
- Log entry: `tg_rate_limit_exceeded`

---

### TC-012: Session State Management

**Objective:** Test conversation state handling

**Steps:**
1. Start onboarding (send `/start` as new user)
2. Enter email
3. Before entering password, send `/start` again
4. Verify onboarding restarts
5. Complete onboarding
6. Verify session cleared
7. Send random text
8. Verify bot doesn't try to process as onboarding

**Expected Results:**
- `/start` resets state
- Sessions properly cleaned up
- No orphaned sessions
- State transitions work correctly

---

### TC-013: Multiple Languages

**Objective:** Verify Persian text displays correctly

**Steps:**
1. Interact with bot in various screens
2. Check all Persian text renders correctly
3. Verify no encoding issues
4. Check button labels readable

**Expected Results:**
- All Persian text displays correctly
- No boxes or question marks
- Button labels clear
- Numbers formatted with commas

---

### TC-014: Error Handling

**Objective:** Test error scenarios

**Test Scenarios:**
1. Upload non-image file for receipt
2. Enter very large amount (e.g., 999999999999)
3. Send gibberish during onboarding
4. Click callback buttons rapidly
5. Send commands in wrong state

**Expected Results:**
- Graceful error messages
- No crashes
- Errors logged
- User can recover

---

### TC-015: Concurrent Users

**Objective:** Test multiple users simultaneously

**Steps:**
1. Have 3-5 testers interact with bot at same time
2. Each performs different actions:
   - User A: Onboarding
   - User B: Wallet top-up
   - User C: View configs
3. Verify no cross-contamination
4. Check each user's session independent

**Expected Results:**
- No session mixing
- All users handled correctly
- No race conditions
- Logs show separate actions

---

## Verification Checklist

After all tests, verify:

- [ ] No plain-text passwords in logs
- [ ] All transactions properly recorded
- [ ] User-telegram links created correctly
- [ ] Sessions cleaned up after completion
- [ ] Structured logs have correct `action` prefixes
- [ ] Rate limiting working
- [ ] No SQL errors in logs
- [ ] Photo uploads stored correctly
- [ ] Telegram notifications sent
- [ ] Reseller upgrades happen automatically

## Log Verification

Check `storage/logs/laravel.log` for:

```
# Successful onboarding
[action] => tg_onboarding_start
[action] => tg_email_collected
[action] => tg_link_complete

# Wallet top-up
[action] => tg_topup_options_shown
[action] => tg_awaiting_topup_amount
[action] => tg_card_payment_initiated
[action] => tg_proof_uploaded
[action] => tg_wallet_credited

# Reseller upgrade
[action] => tg_reseller_upgraded
```

## Common Issues

### Bot not responding
- Check webhook set correctly
- Verify bot token in `.env`
- Check Laravel logs

### Onboarding fails
- Verify migrations ran
- Check email validation
- Look for session errors

### Wallet not updating
- Check transaction status
- Verify event listener registered
- Check ResellerUpgradeService logs

### Photo upload fails
- Verify storage configured
- Check file permissions
- Look for Telegram API errors

## Rollback Plan

If critical issues found:

1. Switch webhook back to old controller:
   ```bash
   curl -X POST "https://api.telegram.org/bot<TOKEN>/setWebhook" \
     -d "url=https://yourdomain.com/webhooks/telegram"
   ```

2. Revert code changes if needed

3. Fix issues and redeploy

## Sign-off

**Tested by:** _______________  
**Date:** _______________  
**Environment:** ☐ Staging ☐ Production  
**Result:** ☐ Pass ☐ Fail ☐ Pass with issues  

**Issues found:** _______________

**Notes:** _______________
