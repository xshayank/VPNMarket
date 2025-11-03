# Reseller Feature Documentation

## Overview
The reseller feature allows bulk VPN config provisioning and management with automatic traffic enforcement, calendar-day time boundaries, grace thresholds, and comprehensive audit logging.

## Application Timezone
The application operates in **Asia/Tehran** timezone. All date/time operations use Tehran time, including:
- Config expiration checks
- Reseller window validation
- Audit log timestamps
- Job scheduling

To configure: Set `APP_TIMEZONE=Asia/Tehran` in `.env` (already set in `config/app.php` default).

## Key Features

### 1. Traffic-Based Reseller Accounts
- Aggregate traffic quota across multiple configs
- Per-reseller time windows with **calendar-day boundaries**
- Automatic suspension when quota exhausted or window expired **at midnight Tehran time**
- Automatic reactivation after recharge/extension

### 2. Config Management
- Bulk config creation from orders
- Individual config traffic limits
- Panel-agnostic provisioning (Marzban, Marzneshin, X-UI)
- Subscription URL generation
- Comment/note support for organization
- **Calendar-day expiration**: Configs expire at 00:00 Tehran time on their end date

### 3. Enforcement System
- **Calendar-Day Boundaries**: Time-based expiry uses start-of-day semantics (no time-expiry grace)
- **Grace Thresholds**: Configurable percentage and absolute grace for traffic limits only
- **Rate Limiting**: Auto-disable/enable at 3 ops/sec to avoid overwhelming panels
- **Retry Logic**: 3 attempts with exponential backoff for remote operations
- **Telemetry**: Detailed tracking of remote success/failure in events and audit logs

### 4. Audit Logging
- Complete audit trail for all status changes
- Domain events for reseller and config lifecycle
- Observer safety net for direct status updates
- Admin UI for viewing and filtering logs
- API endpoint for programmatic access

## Database Schema

### `resellers`
- `id`: Primary key
- `user_id`: Foreign key to users
- `type`: 'traffic' or 'time'
- `status`: 'active', 'suspended', 'expired'
- `traffic_total_bytes`: Total quota in bytes
- `traffic_used_bytes`: Aggregate usage across all configs
- `window_starts_at`: Start of current traffic window (normalized to 00:00 Tehran)
- `window_ends_at`: End of current traffic window (normalized to 00:00 Tehran). Window becomes invalid at this date's midnight.
- `config_limit`: Maximum number of configs allowed
- `username_prefix`: Optional custom prefix for usernames
- `panel_id`: Optional default panel for provisioning

### `reseller_configs`
- `id`: Primary key
- `reseller_id`: Foreign key to resellers
- `panel_id`: Foreign key to panels
- `panel_type`: 'marzban', 'marzneshin', 'xui'
- `panel_user_id`: Username on the remote panel
- `status`: 'active', 'disabled', 'expired', 'deleted'
- `traffic_limit_bytes`: Per-config traffic limit
- `usage_bytes`: Current usage in bytes
- `expires_at`: Config expiration date (normalized to 00:00 Tehran). Config expires at this date's midnight.
- `subscription_url`: Panel-provided subscription URL
- `comment`: Optional note for organization
- `disabled_at`: Timestamp when disabled

### `reseller_config_events`
- `id`: Primary key
- `reseller_config_id`: Foreign key to reseller_configs
- `type`: Event type (see Event Types below)
- `meta`: JSON metadata (reason, telemetry, etc.)
- `created_at`: Event timestamp

### `audit_logs`
- `id`: Primary key
- `action`: Action type (see Audit Actions below)
- `actor_type` / `actor_id`: Polymorphic actor (User/Admin)
- `target_type` / `target_id`: Polymorphic target (Reseller/Config)
- `reason`: Reason code for the action
- `meta`: JSON metadata
- `request_id`, `ip`, `user_agent`: Request context
- `created_at`: Log timestamp

## Event Types

### Config Events (`reseller_config_events.type`)
- `auto_disabled`: System auto-disabled config
- `auto_enabled`: System auto-enabled config
- `manual_disabled`: Admin manually disabled config
- `manual_enabled`: Admin manually enabled config
- `audit_status_changed`: Observer fallback for direct updates
- `usage_reset`: Admin reset usage
- `time_extended`: Admin extended expiration
- `traffic_increased`: Admin increased traffic limit
- `deleted`: Config deleted

### Reseller Events
Tracked in `audit_logs` as `reseller_suspended` and `reseller_activated` actions.

## Audit Actions

### Reseller Actions
- `reseller_created`: New reseller account created
- `reseller_suspended`: Reseller suspended (quota/window)
  - Reasons: `reseller_quota_exhausted`, `reseller_window_expired`
- `reseller_activated`: Reseller reactivated after recovery
  - Reasons: `reseller_recovered`
- `reseller_recharged`: Manual traffic recharge
- `reseller_window_extended`: Manual window extension

### Config Actions
- `config_auto_disabled`: System auto-disabled config
  - Reasons: `reseller_quota_exhausted`, `reseller_window_expired`, `traffic_exceeded`, `time_expired`
- `config_auto_enabled`: System auto-enabled config
  - Reasons: `reseller_recovered`
- `config_manual_disabled`: Admin manually disabled
  - Reasons: `admin_action`
- `config_manual_enabled`: Admin manually enabled
  - Reasons: `admin_action`
- `config_deleted`: Config deleted
  - Reasons: `admin_action`

## Jobs

### SyncResellerUsageJob
**Schedule**: Every N minutes (configurable, default 3)
**Purpose**: Fetch usage, enforce quotas, auto-disable

**Process**:
1. Query all active traffic-based resellers
2. For each reseller:
   - Fetch usage from all active configs
   - Update `usage_bytes` on each config
   - If `allow_config_overrun` is false, check per-config limits (traffic + time expiry)
   - Check time expiry using calendar-day boundaries: `now >= expires_at->startOfDay()`
   - Sum total usage from ALL configs
   - Update `reseller.traffic_used_bytes`
   - Calculate effective traffic limit with grace
   - Check window validity using calendar-day boundaries: `now < window_ends_at->startOfDay()`
   - If exceeded or window expired:
     - Set reseller to `suspended`
     - Emit `reseller_suspended` audit log
     - Auto-disable all active configs (rate-limited at 3/sec)
     - Emit `config_auto_disabled` events and logs

**Grace Calculation (Traffic Only)**:
```php
// Traffic grace is applied
$effectiveLimit = $limit + max($limit * $gracePercent / 100, $graceBytes);

// Time expiry has NO grace - uses calendar-day boundaries
$isExpired = now() >= $expiresAt->copy()->startOfDay();
```

**Calendar-Day Semantics**:
- Config expiring on `2025-11-03` is expired at `2025-11-03 00:00:00` Asia/Tehran
- Reseller window ending on `2025-11-03` becomes invalid at `2025-11-03 00:00:00` Asia/Tehran
- No time-expiry grace is applied (always 0)

### ReenableResellerConfigsJob
**Schedule**: Periodically (configurable)
**Purpose**: Re-enable configs after reseller recovery

**Process**:
1. Find suspended resellers with traffic remaining (with grace) and valid window (calendar-day check)
2. For each eligible reseller:
   - Set status to `active`
   - Emit `reseller_activated` audit log
3. Find configs whose last event is `auto_disabled` with reseller-level reason
4. Re-enable them (rate-limited at 3/sec)
5. Emit `config_auto_enabled` events and logs

## Admin UI

### Settings Page
**Path**: مدیریت فروشندگان > تنظیمات اعمال محدودیت فروشندگان

**Configurable Settings**:
- `reseller.allow_config_overrun`: Allow configs to exceed their own limits
- `reseller.auto_disable_grace_percent`: Traffic grace percentage (0-10%)
- `reseller.auto_disable_grace_bytes`: Minimum traffic grace in bytes
- ~~`reseller.time_expiry_grace_minutes`~~: **Not used** - time expiry uses calendar-day boundaries (no grace)
- `reseller.usage_sync_interval_minutes`: Sync frequency (1-5 min)

**UI Features**:
- Persian RTL interface
- Tabbed layout (Settings / Documentation)
- Real-time grace calculation hints
- Comprehensive inline documentation
- Save notification

### Audit Logs Page
**Path**: Admin Panel > Audit Logs

**Features**:
- Filterable by action, target type, reason, date range
- Global search across all fields
- Auto-refresh every 30 seconds
- Export to CSV
- View modal with formatted JSON metadata
- Color-coded badges for actions and statuses

### Reseller Resource
**Path**: Admin Panel > Resellers

**Actions**:
- Create/edit reseller accounts
- Recharge traffic
- Extend window
- View configs (relation manager)
- Suspend/activate manually

### Configs Relation Manager
**Path**: Reseller > Configs Tab

**Actions per config**:
- Disable/Enable (emits manual events)
- Reset usage
- Extend time
- Increase traffic
- Copy subscription URL
- Delete

**Bulk Actions**:
- Bulk disable/enable
- Bulk delete
- Export CSV

## API Endpoints

### GET /api/admin/audit-logs
**Authentication**: Admin required
**Parameters**:
- `action`: Filter by action type
- `target_type`: Filter by target type (reseller/config)
- `target_id`: Filter by specific target ID
- `reason`: Filter by reason code
- `actor_id`: Filter by actor ID
- `date_from`: Filter by start date
- `date_to`: Filter by end date
- `per_page`: Pagination size (default 15)

**Response**:
```json
{
  "data": [
    {
      "id": 123,
      "action": "config_auto_disabled",
      "actor_type": null,
      "actor_id": null,
      "target_type": "config",
      "target_id": 456,
      "reason": "reseller_quota_exhausted",
      "meta": {
        "remote_success": true,
        "attempts": 1,
        "panel_id": 1,
        "panel_type_used": "marzneshin"
      },
      "created_at": "2025-11-02T10:30:00.000000Z"
    }
  ],
  "links": { ... },
  "meta": { ... }
}
```

## Console Commands

### reseller:enforcement:health
**Usage**: `php artisan reseller:enforcement:health`
**Purpose**: Display system health and diagnostics

**Output**:
- Current enforcement settings
- Queue status (pending/failed jobs)
- Scheduler status
- Reseller statistics (active/suspended counts)
- Config statistics (active/disabled/expired counts)
- Recent audit events (last 24h)
- Most recent 5 enforcement actions

**Use Cases**:
- Verify scheduler is running
- Check if jobs are processing
- Diagnose why configs aren't being disabled/enabled
- Monitor system activity

## Configuration

### Settings Model
Settings are stored in the `settings` table:
```php
Setting::setValue('reseller.auto_disable_grace_percent', 2.0);
$value = Setting::get('reseller.auto_disable_grace_percent', 2.0);
$bool = Setting::getBool('reseller.allow_config_overrun', true);
```

### Recommended Settings
- **Grace Percent**: 2% (good for large quotas)
- **Grace Bytes**: 50 MB (covers sync delays for small quotas)
- **Time Grace**: 0 minutes (strict) or 30 minutes (flexible)
- **Sync Interval**: 3 minutes (balance between responsiveness and load)

## Security & Privacy

### Audit Log Retention
- Audit logs are retained indefinitely by default
- Consider implementing a retention policy (e.g., 90 days)
- Sensitive fields (IP, user agent) can be redacted for GDPR compliance

### Panel Credentials
- Panel credentials are encrypted in the database
- Never logged in audit logs or events
- Only accessible to admin users

### Rate Limiting
- Auto-disable/enable operations are rate-limited to 3/sec
- Prevents overwhelming remote panels
- Ensures operation windows don't cause connection pool exhaustion

## Troubleshooting

### Issue: Configs not auto-disabling despite exceeding quota
**Possible Causes**:
1. Grace settings too permissive
2. Scheduler not running
3. Queue not processing jobs
4. Panel credentials invalid
5. `allow_config_overrun` is enabled and reseller quota not exceeded

**Solution**:
```bash
# Check settings
php artisan reseller:enforcement:health

# Run job manually
php artisan queue:work --once

# Check logs
tail -f storage/logs/laravel.log | grep -i sync
```

### Issue: Configs not re-enabling after recharge
**Possible Causes**:
1. Reseller not recharged (traffic_total_bytes not increased)
2. Window not extended (window_ends_at still expired)
3. Last event is not `auto_disabled` with reseller reason
4. ReenableResellerConfigsJob not scheduled

**Solution**:
```bash
# Verify reseller was recharged
php artisan tinker
>>> $reseller = Reseller::find($id);
>>> $reseller->hasTrafficRemaining();
>>> $reseller->isWindowValid();

# Check last event on config
>>> $config = ResellerConfig::find($id);
>>> $config->events()->latest()->first();

# Run job manually
php artisan queue:work --once
```

### Issue: No audit logs appearing
**Possible Causes**:
1. Scheduler not set up in cron
2. Jobs not being queued
3. Observer not registered

**Solution**:
```bash
# Verify observer is registered
grep -n "ResellerConfig::observe" app/Providers/AppServiceProvider.php

# Check cron
crontab -l | grep schedule:run

# Test audit log creation
php artisan tinker
>>> AuditLog::log('test_action', 'reseller', 1, 'test_reason', ['foo' => 'bar']);
>>> AuditLog::latest()->first();
```

## Testing

### Test Files
- `tests/Feature/AuditLogsAutoFlowsTest.php`: Tests auto-disable/enable audit logging
- `tests/Feature/ResellerAuditObserverTest.php`: Tests observer safety net
- `tests/Feature/ResellerUsageSyncTest.php`: Tests usage sync and enforcement
- `tests/Feature/ResellerGraceThresholdsTest.php`: Tests grace calculations

### Run Tests
```bash
# Run all audit logging tests
php artisan test tests/Feature/AuditLogsAutoFlowsTest.php

# Run observer tests
php artisan test tests/Feature/ResellerAuditObserverTest.php

# Run specific test
php artisan test --filter=test_auto_disable_emits_audit_logs_and_suspends_reseller
```

## Migration Path

### From Non-Audit System
If upgrading from a system without audit logging:

1. Run migrations:
   ```bash
   php artisan migrate
   ```

2. Verify observer is registered:
   ```bash
   grep ResellerConfigObserver app/Providers/AppServiceProvider.php
   ```

3. Configure settings via Admin UI or:
   ```bash
   php artisan tinker
   >>> Setting::setValue('reseller.auto_disable_grace_percent', 2.0);
   >>> Setting::setValue('reseller.auto_disable_grace_bytes', 52428800);
   ```

4. Test enforcement:
   ```bash
   # Run sync job manually
   php artisan queue:work --once
   
   # Check logs were created
   php artisan reseller:enforcement:health
   ```

## Related Documentation
- [AUDIT_LOGS.md](AUDIT_LOGS.md) - Detailed audit logging and settings guide
- [AUDIT_LOG_IMPLEMENTATION.md](AUDIT_LOG_IMPLEMENTATION.md) - Implementation details
- [RESELLER_MANAGEMENT_TESTING_GUIDE.md](RESELLER_MANAGEMENT_TESTING_GUIDE.md) - Testing procedures
