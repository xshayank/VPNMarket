# Email Center Feature Implementation Summary

## Overview
This implementation adds a comprehensive Email Management section to the Filament admin panel for managing manual email campaigns and automated reminders for VPN users and resellers.

## Files Created/Modified

### Filament Admin Page
- **app/Filament/Pages/EmailCenter.php** - Main admin page with:
  - Manual send actions for expired users
  - Automation toggles and settings
  - Navigation group: "ایمیل" (Email)
  - Icon: heroicon-o-envelope

### Mailable Classes (app/Mail/)
1. **NormalUserExpiredMail.php** - Email for expired normal users
2. **ResellerExpiredMail.php** - Email for expired reseller users
3. **RenewalReminderMail.php** - Reminder for users near expiry + low wallet
4. **ResellerTrafficTimeReminderMail.php** - Reminder for resellers near limits

### Email Templates (resources/views/emails/)
1. **normal-user-expired.blade.php** - Bilingual template (FA/EN)
2. **reseller-expired.blade.php** - Bilingual template (FA/EN)
3. **renewal-reminder.blade.php** - Bilingual template (FA/EN)
4. **reseller-traffic-time-reminder.blade.php** - Bilingual template (FA/EN)

### Job Classes (app/Jobs/)
1. **SendExpiredNormalUsersEmailsJob.php** - Queued job for expired normal users
2. **SendExpiredResellerUsersEmailsJob.php** - Queued job for expired resellers
3. **SendRenewalWalletRemindersJob.php** - Queued job for renewal reminders
4. **SendResellerTrafficTimeRemindersJob.php** - Queued job for reseller warnings

### Modified Files
- **routes/console.php** - Added scheduled tasks:
  - Daily at 09:00: SendRenewalWalletRemindersJob (if enabled)
  - Hourly: SendResellerTrafficTimeRemindersJob (if enabled)

- **app/Models/Setting.php** - Added helper methods:
  - `getValue(key, default)` - Get setting value
  - `setValue(key, value)` - Set setting value
  - `getBool(key, default)` - Get boolean setting
  - `getInt(key, default)` - Get integer setting

### View File
- **resources/views/filament/pages/email-center.blade.php** - Blade template for EmailCenter page

### Tests
- **tests/Feature/EmailCenterTest.php** - Comprehensive test suite (11 tests, all passing)

## Settings Stored in Database

The following settings are stored in the `settings` table:

1. **email.auto_remind_renewal_wallet** (bool) - Enable/disable renewal reminders
2. **email.renewal_days_before** (int, default: 3) - Days before expiry to send reminder
3. **email.min_wallet_threshold** (int, default: 10000) - Minimum wallet balance threshold
4. **email.auto_remind_reseller_traffic_time** (bool) - Enable/disable reseller reminders
5. **email.reseller_days_before_end** (int, default: 3) - Days before window end to send reminder
6. **email.reseller_traffic_threshold_percent** (int, default: 10) - Traffic remaining % threshold

## Query Logic

### Expired Normal Users
- Users with at least one paid order where:
  - `plan_id` is not null
  - `expires_at <= now()`
  - No other active orders (`expires_at > now()`)
- Each user emailed once

### Expired Reseller Users
- Resellers where:
  - `type = 'traffic'`
  - `window_ends_at <= now()` OR `traffic_used_bytes >= traffic_total_bytes`

### Renewal & Wallet Reminders
- Users with paid orders expiring within N days
- `user.balance < min_wallet_threshold`
- No other active longer orders for same user

### Reseller Traffic/Time Reminders
- Resellers where:
  - `type = 'traffic'`
  - Days remaining <= threshold OR remaining traffic % <= threshold

## Features

### Manual Send Actions
1. **Send to Expired Normal Users** - Dispatches job to email all expired normal users
2. **Send to Expired Resellers** - Dispatches job to email all expired resellers
3. **Run Reminders Now** - Manually trigger both reminder jobs

### Automated Reminders
- Toggles to enable/disable automations
- Configurable thresholds and timeframes
- Scheduled execution via Laravel scheduler
- Jobs guard themselves by checking settings at runtime

### Email Delivery
- All emails are queued (non-blocking)
- Batch processing using `chunkById(100)`
- Bilingual templates (Persian/English)
- Professional HTML styling

## Testing

All 11 tests pass successfully:

1. ✓ Email center page renders successfully
2. ✓ Email center form displays automation settings
3. ✓ Settings are loaded correctly from database
4. ✓ Settings can be saved
5. ✓ Manual send to expired normal users dispatches job
6. ✓ Manual send to expired resellers dispatches job
7. ✓ Run reminders now dispatches both reminder jobs
8. ✓ Expired normal users count is calculated correctly
9. ✓ Expired resellers count is calculated correctly
10. ✓ Toggle fields visibility based on automation switches
11. ✓ Setting helper methods work correctly

## Usage Instructions

### Admin Access
1. Navigate to Filament admin panel
2. Look for "ایمیل" (Email) in sidebar navigation
3. Click on "مرکز مدیریت ایمیل" (Email Center)

### Manual Email Campaigns
1. Use header action buttons:
   - "ارسال به کاربران عادی منقضی شده" - Send to expired normal users
   - "ارسال به ریسلرهای منقضی شده" - Send to expired resellers
2. Confirm in modal dialog
3. Emails are queued for background processing

### Automated Reminders
1. Toggle switches to enable/disable features
2. Configure thresholds and timeframes
3. Click "ذخیره تنظیمات" (Save Settings)
4. Jobs will run automatically based on schedule

### Manual Reminder Trigger
- Use "اجرای یادآوری‌ها الان" (Run Reminders Now) button
- Dispatches both reminder jobs immediately
- Bypasses schedule, but respects automation toggles

## Production Deployment

### Requirements
1. Queue worker must be running: `php artisan queue:work`
2. Scheduler must be running: Add to cron: `* * * * * php artisan schedule:run`
3. Mail configuration must be set in `.env`

### Environment Variables
```env
MAIL_MAILER=smtp
MAIL_HOST=your-smtp-host
MAIL_PORT=587
MAIL_USERNAME=your-username
MAIL_PASSWORD=your-password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@example.com
MAIL_FROM_NAME="${APP_NAME}"
```

## Logs
All email operations are logged with info-level logs containing:
- Job start/completion
- Email counts queued
- Settings checks

Example log entries:
```
[info] Starting SendExpiredNormalUsersEmailsJob
[info] SendExpiredNormalUsersEmailsJob completed. Queued 42 emails.
[info] SendRenewalWalletRemindersJob skipped: auto_remind_renewal_wallet is disabled
```

## Security Considerations
- All emails are queued to prevent blocking
- Batch processing prevents memory issues
- Settings checked at runtime for each scheduled job
- Admin-only access via Filament's built-in auth

## Performance
- Chunked processing (100 records per batch)
- Queued email delivery
- Efficient query logic avoiding N+1 problems
- Indexes recommended on: `orders.expires_at`, `orders.status`, `resellers.type`
