# Telegram Bot Onboarding & Wallet Features

This document describes the new Telegram bot features that provide end-to-end onboarding, wallet management, and automatic reseller upgrades.

## Overview

The reworked Telegram bot provides:

1. **Guided Onboarding**: Users can create full site accounts via Telegram
2. **Wallet Top-up**: Multiple payment methods (Card-to-Card, StarsEfar)
3. **Auto-upgrade to Reseller**: Automatic promotion when balance reaches threshold
4. **Rich Main Menu**: Context-aware UI with wallet, account, and reseller features
5. **Config Management**: Resellers can view and manage their configurations

## Architecture

### Database Schema

#### user_telegram_links
Links site users to Telegram chat IDs for better separation of concerns.

```sql
- id
- user_id (FK to users)
- chat_id (bigint, unique)
- username (nullable)
- first_name (nullable)
- last_name (nullable)
- verified_at (timestamp)
- meta (json)
- created_at, updated_at
```

#### telegram_sessions
Manages stateful conversations for onboarding and payment flows.

```sql
- id
- chat_id (bigint, indexed)
- state (string, indexed)
- data (json)
- last_activity_at (timestamp)
- created_at, updated_at
```

### Services

#### PaymentMethodService
- Centralized payment method configuration
- Respects admin toggles from Theme Settings
- Lists enabled methods: Card-to-Card, StarsEfar

#### ResellerUpgradeService
- Checks user balance against threshold (default: 100,000 تومان)
- Creates wallet-based reseller automatically
- Transfers user balance to reseller wallet
- Idempotent operations

#### BotRouter
- State machine for conversation flows
- Routes commands and callbacks
- Handles onboarding, payments, config management
- Session management

#### BotRenderer
- UI generation with context-aware keyboards
- Inline button menus
- Formatted messages with wallet balance, status

### Controllers

#### NewWebhookController
- Entry point for Telegram webhook
- Rate limiting: 5 requests per 10 seconds per chat
- Routes updates to BotRouter

## Features

### 1. Onboarding Flow

**Flow:**
1. User sends `/start`
2. Bot prompts for email
3. Email validation (RFC format)
4. Check if email already exists
5. Prompt for password (min 8 characters)
6. Confirm password
7. Create User account with verified email
8. Create UserTelegramLink
9. Show main menu

**Security:**
- Passwords are hashed immediately
- No plain-text passwords in logs
- Session data is cleared after completion
- Email uniqueness validation

### 2. Wallet Top-up

**Supported Methods:**

#### Card-to-Card (کارت به کارت)
1. User selects Card-to-Card method
2. Enters amount (minimum 10,000 تومان)
3. Bot displays card details
4. User transfers money and uploads receipt photo
5. Transaction created with status `pending`
6. Admin reviews in "تاییدیه شارژ کیف پول"
7. On approval:
   - Balance is credited
   - TransactionCompleted event fires
   - User receives Telegram notification
   - Auto-upgrade check runs

#### StarsEfar (استارز ایفار)
- Currently shows placeholder (gateway integration needed)
- Transaction created for future processing

**Transaction Flow:**
```
User requests topup
  → Bot shows payment methods
  → User selects method
  → Bot creates TelegramSession (awaiting_topup_amount)
  → User enters amount
  → Bot creates Transaction (status: pending)
  → For Card-to-Card: session → awaiting_card_proof
  → User uploads photo
  → Transaction updated with proof_image_path
  → Admin approves in Filament
  → Transaction status → completed
  → TransactionCompleted event
  → NotifyUserViaTelegram listener
  → Wallet credited
  → ResellerUpgradeService checks threshold
  → If threshold met: create Reseller
  → Send notification with upgrade status
```

### 3. Auto-upgrade to Reseller

**Threshold:**
- Default: 100,000 تومان
- Configurable via `RESELLER_MIN_WALLET_UPGRADE` environment variable
- Config key: `billing.reseller.min_wallet_upgrade`

**Process:**
1. Transaction completed
2. User balance updated
3. `ResellerUpgradeService::checkAndUpgrade()` called
4. If balance ≥ threshold and no existing reseller:
   - Create Reseller (type: wallet, status: active)
   - Transfer user balance to reseller wallet
   - Log structured event: `tg_reseller_upgraded`
5. Notification sent via Telegram

**Idempotency:**
- Checks if user already has reseller before creating
- Uses DB transactions for atomicity
- Safe to call multiple times

### 4. Main Menu & UI

#### For All Users:
- 👤 My Account: email, telegram link status, balance
- 💰 Wallet: balance, top-up, transaction history
- 🎖 Become Reseller: shows progress to threshold
- ❓ Help: usage instructions
- 💬 Support: (future ticket integration)

#### For Resellers:
- 🎖 Reseller Dashboard: balance, config count, status
- ⚙️ My Configs: list of configurations (10 most recent)
  - Shows: name, panel, status, ID
  - Future: enable/disable, reset usage, subscription URL

### 5. Event System

#### TransactionCompleted Event
Fired when a transaction reaches `completed` status.

**Listener: NotifyUserViaTelegram**
1. Checks if user has Telegram link
2. Credits wallet (handled by Filament action)
3. Calls ResellerUpgradeService
4. Sends notification with:
   - Amount credited
   - New balance
   - Upgrade status (if applicable)

**Structured Logging:**
All bot actions log with:
- `action`: prefixed with `tg_` (e.g., `tg_onboarding_start`, `tg_wallet_credited`)
- `user_id`
- `chat_id`
- Context-specific data

## Configuration

### Environment Variables

```env
# Telegram Bot Token
TELEGRAM_BOT_TOKEN=your_bot_token_here

# Reseller auto-upgrade threshold (in تومان)
RESELLER_MIN_WALLET_UPGRADE=100000

# Wallet-based reseller settings
WALLET_PRICE_PER_GB=780
WALLET_SUSPENSION_THRESHOLD=-1000
```

### Configuration Files

**config/billing.php:**
```php
'reseller' => [
    'min_wallet_upgrade' => env('RESELLER_MIN_WALLET_UPGRADE', 100000),
],
```

## Deployment

### 1. Run Migrations

```bash
php artisan migrate
```

This creates:
- `user_telegram_links` table
- `telegram_sessions` table

### 2. Configure Telegram Bot

1. Create bot via @BotFather
2. Get bot token
3. Set in `.env`: `TELEGRAM_BOT_TOKEN=...`

### 3. Set Webhook

Choose one of the following:

**Option A: Use New Bot (Recommended)**
```bash
# Set webhook to new controller
curl -X POST "https://api.telegram.org/bot<YOUR_BOT_TOKEN>/setWebhook" \
  -d "url=https://yourdomain.com/webhooks/telegram-new"
```

**Option B: Keep Old Bot**
```bash
# Continue using old webhook
curl -X POST "https://api.telegram.org/bot<YOUR_BOT_TOKEN>/setWebhook" \
  -d "url=https://yourdomain.com/webhooks/telegram"
```

### 4. Clear Caches

```bash
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
```

### 5. Verify Webhook

```bash
curl "https://api.telegram.org/bot<YOUR_BOT_TOKEN>/getWebhookInfo"
```

Should show:
```json
{
  "ok": true,
  "result": {
    "url": "https://yourdomain.com/webhooks/telegram-new",
    "has_custom_certificate": false,
    "pending_update_count": 0,
    "max_connections": 40
  }
}
```

## Testing

### Onboarding

1. Send `/start` to bot
2. Enter email: `test@example.com`
3. Enter password: `password123`
4. Confirm password: `password123`
5. Verify account created in database
6. Check `user_telegram_links` table

### Wallet Top-up (Card-to-Card)

1. Click "💰 Wallet"
2. Click "💳 Top-up Wallet"
3. Select "کارت به کارت"
4. Enter amount: `50000`
5. Note card details
6. Upload receipt photo
7. Admin: go to Filament → "تاییدیه شارژ کیف پول"
8. Approve transaction
9. Verify Telegram notification received
10. Check balance updated

### Reseller Upgrade

1. Top up wallet to ≥ 100,000 تومان
2. Verify notification includes upgrade message
3. Check `resellers` table for new record
4. Verify main menu now shows reseller options
5. Click "🎖 Reseller Dashboard"
6. Verify dashboard shows balance and configs

## Security Considerations

### Implemented

✅ Password hashing before storage  
✅ No plain-text password logging  
✅ Email validation  
✅ Rate limiting (5 req/10s per chat)  
✅ Transaction idempotence  
✅ Photo upload validation  
✅ Session cleanup after completion  

### Recommendations

⚠️ Consider adding magic-link login as alternative to password-in-Telegram  
⚠️ Implement session timeouts (e.g., 15 minutes of inactivity)  
⚠️ Add CAPTCHA for high-frequency requests  
⚠️ Monitor for abuse patterns in structured logs  

## Structured Logging

All bot actions log with `action` field prefixed by `tg_`:

- `tg_onboarding_start`: User begins onboarding
- `tg_email_collected`: Email input validated
- `tg_link_complete`: User created and linked
- `tg_user_creation_failed`: Error during user creation
- `tg_topup_options_shown`: Payment methods displayed
- `tg_awaiting_topup_amount`: User selecting amount
- `tg_card_payment_initiated`: Card-to-card flow started
- `tg_starsefar_payment_requested`: StarsEfar flow started
- `tg_proof_uploaded`: Payment proof received
- `tg_proof_upload_failed`: Error uploading proof
- `tg_wallet_credited`: Balance updated
- `tg_reseller_upgraded`: User promoted to reseller
- `tg_reseller_upgrade_failed`: Upgrade error
- `tg_notification_failed`: Error sending notification
- `tg_rate_limit_exceeded`: Rate limit hit

## API Reference

### BotRouter Methods

```php
// Route incoming update
public function route($update): void

// Start onboarding for new user
protected function startOnboarding($chatId, $update): void

// Show main menu
protected function showMainMenu(User $user, $chatId): void

// Display wallet menu
protected function showWallet(User $user, $chatId): void

// Show topup payment methods
protected function showTopupOptions(User $user, $chatId): void

// Display reseller dashboard
protected function showResellerDashboard(User $user, $chatId): void

// List user configs
protected function showMyConfigs(User $user, $chatId): void
```

### ResellerUpgradeService Methods

```php
// Check and upgrade user if eligible
public function checkAndUpgrade(User $user): array
// Returns: ['upgraded' => bool, 'reseller' => ?Reseller, 'message' => string]

// Get amount needed to reach threshold
public function getAmountNeeded(User $user): int

// Check if user can be upgraded
public function canUpgrade(User $user): bool
```

### PaymentMethodService Methods

```php
// Get all enabled payment methods
public function getEnabledMethods(): array

// Check if specific method is enabled
public function isMethodEnabled(string $methodId): bool

// Get method details
public function getMethodDetails(string $methodId): ?array
```

## Troubleshooting

### Bot not responding
1. Check webhook is set: `getWebhookInfo`
2. Verify bot token in `.env`
3. Check Laravel logs: `storage/logs/laravel.log`
4. Look for `Telegram Webhook Received` log entries

### Onboarding fails
1. Check migrations ran: `php artisan migrate:status`
2. Verify `user_telegram_links` and `telegram_sessions` tables exist
3. Check for validation errors in logs
4. Ensure email is unique

### Wallet not updating
1. Verify transaction is `completed` in database
2. Check `TransactionCompleted` event fires
3. Verify `NotifyUserViaTelegram` listener registered
4. Check for errors in `tg_wallet_credited` logs

### Reseller not upgrading
1. Verify balance ≥ threshold
2. Check `billing.reseller.min_wallet_upgrade` config
3. Review `tg_reseller_upgraded` or `tg_reseller_upgrade_failed` logs
4. Ensure user doesn't already have reseller

### Rate limiting
- Default: 5 requests per 10 seconds per chat
- Check for `tg_rate_limit_exceeded` in logs
- Users will need to wait before retrying

## Future Enhancements

### Phase 2 Features
- [ ] Config enable/disable with remote-first Eylandoo toggle
- [ ] Config reset usage
- [ ] Subscription URL display with copy button
- [ ] Config creation wizard
- [ ] StarsEfar gateway integration
- [ ] Magic-link authentication
- [ ] Support ticket integration
- [ ] Multi-language support
- [ ] Referral system integration

### Potential Improvements
- [ ] Session timeout handling
- [ ] Pagination for config lists
- [ ] Search/filter configs
- [ ] Bulk config operations
- [ ] Usage statistics in bot
- [ ] Expiry reminders
- [ ] Promotional notifications
- [ ] Admin commands

## Support

For issues or questions:
1. Check structured logs with `tg_` action prefix
2. Review transaction status in Filament
3. Verify webhook configuration
4. Check Laravel logs

## License

Same as parent project.
