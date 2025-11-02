# Audit Logs & Reseller Enforcement Settings

## Overview
This document describes the audit logging system for reseller auto-disable/enable operations and the Admin UI for configuring enforcement settings.

## Admin Settings UI

### Accessing Settings
Navigate to **مدیریت فروشندگان > تنظیمات اعمال محدودیت فروشندگان** (Reseller Management > Reseller Enforcement Settings) in the Filament Admin panel.

### Available Settings

#### 1. Allow Config Overrun (اجازه تجاوز از محدودیت کانفیگ)
- **Key**: `reseller.allow_config_overrun`
- **Type**: Boolean (Toggle)
- **Default**: `true`
- **Description**: When enabled, individual configs are not disabled when they exceed their own traffic limits. Instead, only the reseller-level quota is enforced. When disabled, configs are disabled individually when they exceed their traffic limit (with grace).

#### 2. Usage Sync Interval (فاصله زمانی همگام‌سازی مصرف)
- **Key**: `reseller.usage_sync_interval_minutes`
- **Type**: Integer (1-5 minutes)
- **Default**: `3`
- **Description**: How often (in minutes) the system fetches usage data from panels and checks for quota/window violations.

#### 3. Auto Disable Grace Percent (درصد Grace ترافیک)
- **Key**: `reseller.auto_disable_grace_percent`
- **Type**: Float (0-10%)
- **Default**: `2.0`
- **Description**: Percentage of additional traffic allowed beyond the limit before auto-disabling. Applied to reseller-level quotas.
- **Example**: For a 100 GB quota with 2% grace, the effective limit is 102 GB.

#### 4. Auto Disable Grace Bytes (حداقل Grace ترافیک)
- **Key**: `reseller.auto_disable_grace_bytes`
- **Type**: Integer (bytes)
- **Default**: `52428800` (50 MB)
- **Description**: Minimum absolute traffic grace in bytes. The system uses the maximum of percentage grace and absolute grace.
- **Example**: For a 1 GB quota with 2% grace (20 MB) and 50 MB absolute grace, the effective grace is 50 MB.

#### 5. Time Expiry Grace Minutes (فرصت Grace زمانی)
- **Key**: `reseller.time_expiry_grace_minutes`
- **Type**: Integer (0-1440 minutes)
- **Default**: `0`
- **Description**: Additional time (in minutes) after config expiration before auto-disabling. Set to 0 for no grace period.

### Grace Calculation

The effective limit with grace is calculated as:

```
effective_limit = base_limit + max(base_limit × grace_percent / 100, grace_bytes)
```

**Example Scenarios:**

1. **Large Quota (100 GB)**
   - Base limit: 100 GB
   - Grace percent: 2% → 2 GB
   - Grace bytes: 50 MB
   - Effective limit: 100 + max(2 GB, 50 MB) = **102 GB**

2. **Small Quota (500 MB)**
   - Base limit: 500 MB
   - Grace percent: 2% → 10 MB
   - Grace bytes: 50 MB
   - Effective limit: 500 + max(10 MB, 50 MB) = **550 MB**

## Audit Log Actions

### Reseller-Level Actions

#### `reseller_suspended`
**When**: Reseller's aggregate usage exceeds quota or window expires
**Reason codes**:
- `reseller_quota_exhausted`: Total traffic usage exceeded quota (with grace)
- `reseller_window_expired`: Time window has expired

**Metadata**:
```json
{
  "traffic_used_bytes": 1200000000,
  "traffic_total_bytes": 1000000000,
  "window_ends_at": "2025-11-30 23:59:59"
}
```

#### `reseller_activated`
**When**: Suspended reseller is reactivated after recharge/window extension
**Reason codes**:
- `reseller_recovered`: Reseller now has traffic remaining and valid window

**Metadata**:
```json
{
  "traffic_used_bytes": 1000000000,
  "traffic_total_bytes": 10000000000,
  "window_ends_at": "2025-12-31 23:59:59"
}
```

### Config-Level Actions

#### `config_auto_disabled`
**When**: Config is automatically disabled by the system
**Reason codes**:
- `reseller_quota_exhausted`: Disabled because reseller quota exceeded
- `reseller_window_expired`: Disabled because reseller window expired
- `traffic_exceeded`: Config's own traffic limit exceeded (only if `allow_config_overrun` is false)
- `time_expired`: Config's expiration time has passed

**Metadata**:
```json
{
  "reason": "reseller_quota_exhausted",
  "remote_success": true,
  "attempts": 1,
  "last_error": null,
  "panel_id": 1,
  "panel_type_used": "marzneshin"
}
```

#### `config_auto_enabled`
**When**: Config is automatically re-enabled after reseller recovery
**Reason codes**:
- `reseller_recovered`: Re-enabled because reseller has quota again

**Metadata**: Same structure as `config_auto_disabled`

#### `config_manual_disabled` / `config_manual_enabled`
**When**: Admin manually disables/enables a config via the UI
**Reason codes**:
- `admin_action`: Manual action by administrator

**Metadata**: Same structure as auto actions, but includes actor information

## Health Diagnostic Command

### Usage
```bash
php artisan reseller:enforcement:health
```

### Output Sections

1. **Current Enforcement Settings**
   - Displays all current grace and enforcement settings
   - Shows values in human-readable format (bytes → MB/GB)

2. **Queue Configuration**
   - Shows queue connection type
   - For database queues: displays pending and failed job counts

3. **Scheduler Status**
   - Shows last detected schedule:run execution
   - Provides instructions for verifying cron setup

4. **Reseller Statistics**
   - Total, active, and suspended reseller counts
   - Config status breakdown (active/disabled/expired)

5. **Recent Audit Events**
   - Count of each audit action type in last 24 hours
   - Lists 5 most recent enforcement events
   - Warns if no events detected (may indicate scheduler issues)

### Example Output
```
=== Reseller Enforcement System Health Check ===

📋 Current Enforcement Settings:

  reseller.allow_config_overrun: ✓ Enabled
  reseller.auto_disable_grace_percent: 2
  reseller.auto_disable_grace_bytes: 52428800 bytes (50 MB)
  reseller.time_expiry_grace_minutes: 0
  reseller.usage_sync_interval_minutes: 3

🔗 Queue Configuration:

  Default connection: database
  Pending jobs: 0
  Failed jobs: 0

📊 Reseller Statistics:

  Total traffic-based resellers: 15
  Active: 12
  Suspended: 3

  Total configs: 145
  Active: 120
  Disabled: 20
  Expired: 5

📝 Recent Audit Events (last 24 hours):

  ✓ reseller_suspended: 3
  ✓ reseller_activated: 1
  ✓ config_auto_disabled: 25
  ✓ config_auto_enabled: 8
  - config_manual_disabled: 0
  - config_manual_enabled: 0

  Most recent enforcement events:
    • [2 hours ago] config_auto_disabled (reason: reseller_quota_exhausted)
    • [3 hours ago] reseller_suspended (reason: reseller_quota_exhausted)
```

## Enforcement Flow

### Auto-Disable Process

1. **SyncResellerUsageJob** runs every N minutes (configured in settings)
2. For each active traffic-based reseller:
   - Fetch usage from all active configs
   - Sum total usage from ALL configs (active + disabled)
   - Update `reseller.traffic_used_bytes`
   - Calculate effective limit with grace
3. If reseller exceeds limit or window expired:
   - Update reseller status to `suspended`
   - Emit `reseller_suspended` audit log
   - Disable all active configs (rate-limited at 3/sec)
   - For each config:
     - Emit `ResellerConfigEvent` with `type='auto_disabled'`
     - Emit `config_auto_disabled` audit log

### Auto-Enable Process

1. **ReenableResellerConfigsJob** runs periodically
2. Find suspended resellers with:
   - Traffic remaining (usage < limit with grace)
   - Valid time window
3. For each eligible reseller:
   - Update reseller status to `active`
   - Emit `reseller_activated` audit log
   - Find configs disabled due to reseller-level reasons
   - Re-enable them (rate-limited at 3/sec)
   - For each config:
     - Emit `ResellerConfigEvent` with `type='auto_enabled'`
     - Emit `config_auto_enabled` audit log

### Manual Actions (Admin UI)

When admin manually disables/enables configs via ConfigsRelationManager:
1. Resolve panel from `config.panel` or `Panel::find(config.panel_id)`
2. Call provisioner to disable/enable on remote panel
3. Update local config status
4. Emit `ResellerConfigEvent` with `type='manual_disabled'/'manual_enabled'`
5. Emit `config_manual_disabled`/`config_manual_enabled` audit log
6. Include telemetry (remote success, attempts, errors)

## Observer Safety Net

The `ResellerConfigObserver` registered in `AppServiceProvider` provides a fallback audit trail:
- Monitors all `status` changes on `ResellerConfig` models
- Creates `audit_status_changed` event if no recent domain event exists
- Captures actor, from/to status, panel info, route, and IP
- Only triggers if no proper event was recorded in last 2 seconds
- Logs at notice level for visibility

This ensures no status change goes unrecorded, even if a code path fails to emit proper events.

## Related Documentation

- [AUDIT_LOG_IMPLEMENTATION.md](AUDIT_LOG_IMPLEMENTATION.md) - Complete audit log system documentation
- [docs/AUDIT_LOGS.md](docs/AUDIT_LOGS.md) - Detailed API and usage guide
- [RESELLER_MANAGEMENT_TESTING_GUIDE.md](RESELLER_MANAGEMENT_TESTING_GUIDE.md) - Testing procedures

## Troubleshooting

### No Audit Logs Appearing

1. Check scheduler is running:
   ```bash
   # Verify cron is set up
   crontab -l | grep schedule:run
   
   # Run health check
   php artisan reseller:enforcement:health
   ```

2. Check queue is processing:
   ```bash
   # For database queue
   php artisan queue:work --once
   
   # Check failed jobs
   php artisan queue:failed
   ```

3. Check settings are configured:
   ```bash
   # View all reseller settings
   php artisan tinker
   >>> Setting::where('key', 'like', 'reseller.%')->get()
   ```

### Configs Not Being Auto-Disabled

1. Verify grace settings aren't too permissive
2. Check reseller has `type='traffic'` and `status='active'`
3. Verify configs have valid `panel_id` and `panel_user_id`
4. Check panel credentials are valid
5. Review logs for provisioner errors:
   ```bash
   tail -f storage/logs/laravel.log | grep -i "disable\|provision"
   ```

### Configs Not Being Auto-Enabled

1. Verify reseller was recharged (increased `traffic_total_bytes`)
2. Check window was extended (`window_ends_at` is in future)
3. Verify configs' last event is `auto_disabled` with reseller-level reason
4. Check ReenableResellerConfigsJob is scheduled and running

## Testing

Run the audit logging tests:
```bash
# Test auto-disable flow
php artisan test tests/Feature/AuditLogsAutoFlowsTest.php::test_auto_disable_emits_audit_logs_and_suspends_reseller

# Test auto-enable flow
php artisan test tests/Feature/AuditLogsAutoFlowsTest.php::test_auto_enable_after_recharge_emits_audit_logs

# Test observer safety net
php artisan test tests/Feature/ResellerAuditObserverTest.php

# Run all audit tests
php artisan test tests/Feature/AuditLogsAutoFlowsTest.php tests/Feature/ResellerAuditObserverTest.php
```
