# Audit Logs Documentation

## Overview

The Audit Logs system provides a comprehensive, queryable trail of all important administrative and system actions in the VPNMarket panel. Every significant event—from config creation to reseller suspension—is recorded with detailed metadata, including remote operation telemetry and reason codes.

## Purpose

- **Accountability**: Track who performed what action and when
- **Troubleshooting**: Debug issues by reviewing the sequence of events
- **Compliance**: Maintain records for audit and compliance purposes
- **Monitoring**: Identify patterns and anomalies in system behavior
- **Recovery**: Understand the state of the system at any point in time

## Database Schema

### Table: `audit_logs`

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint | Primary key |
| `action` | string | Action identifier (indexed) |
| `actor_type` | string | Polymorphic actor class (nullable) |
| `actor_id` | bigint | Polymorphic actor ID (nullable) |
| `target_type` | string | Target entity type |
| `target_id` | bigint | Target entity ID (nullable) |
| `reason` | string | Action reason code (nullable) |
| `request_id` | string | HTTP request ID (nullable) |
| `ip` | string | IP address of actor (nullable) |
| `user_agent` | string | User agent string (nullable) |
| `meta` | json | Additional metadata (nullable) |
| `created_at` | timestamp | When the log was created |
| `updated_at` | timestamp | When the log was updated |

**Indexes:**
- `action` (single column)
- `target_type, target_id` (composite)
- `created_at` (single column)

## Action Types

### Reseller Actions

- **`reseller_created`**: New reseller account created
- **`reseller_suspended`**: Reseller suspended (quota exhausted or window expired)
- **`reseller_activated`**: Reseller reactivated (recovered)
- **`reseller_recharged`**: Traffic quota increased
- **`reseller_window_extended`**: Time window extended
- **`reseller_status_changed`**: General status change (fallback)

### Config Actions

- **`config_manual_disabled`**: Config manually disabled by user/admin
- **`config_manual_enabled`**: Config manually enabled by user/admin
- **`config_auto_disabled`**: Config automatically disabled by system
- **`config_auto_enabled`**: Config automatically enabled by system
- **`config_expired`**: Config expired due to time limit
- **`config_deleted`**: Config deleted

## Reason Codes

- **`traffic_exceeded`**: Config disabled due to traffic limit breach
- **`time_expired`**: Config disabled due to expiration date
- **`reseller_quota_exhausted`**: Config disabled because reseller quota exhausted
- **`reseller_window_expired`**: Config disabled because reseller window expired
- **`reseller_recovered`**: Config enabled because reseller recovered quota
- **`admin_action`**: Manual action by admin or user
- **`audit_reseller_status_changed`**: Fallback observer detected a change

## Metadata Fields

The `meta` JSON column stores additional context:

- **`remote_success`** (boolean): Whether remote panel operation succeeded
- **`attempts`** (int): Number of retry attempts
- **`last_error`** (string): Error message from last attempt
- **`panel_id`** (int): Panel ID used for operation
- **`panel_type_used`** (string): Panel type (marzban/marzneshin/xui)
- **`traffic_used_bytes`** (int): Traffic usage at time of action
- **`traffic_total_bytes`** (int): Total traffic allocation
- **`window_ends_at`** (datetime): Window expiration time
- **`old_status`** / **`new_status`**: Status transitions
- **`changes`**: Array of changed attributes

## Admin UI (Filament Resource)

### Navigation
Access via: **System > Audit Logs** (admin-only)

### Table Columns
- **Date/Time**: When the action occurred (sortable)
- **Action**: Badge with color-coded action type
- **Actor**: Who performed the action (User/Admin/System)
- **Target Type**: Entity type (Reseller/Config/etc.)
- **Target ID**: Entity identifier
- **Reason**: Why the action occurred
- **Remote OK**: Icon showing remote operation success
- **Attempts**: Number of retry attempts
- **IP Address**: Actor's IP (hidden by default)

### Filters
- **Action**: Multi-select filter for specific actions
- **Target Type**: Filter by entity type
- **Reason**: Multi-select filter for reason codes
- **Remote Success**: Ternary filter (true/false/any)
- **Date Range**: From/to date picker

### Search
Global search across:
- Action names
- Reason codes
- Target IDs
- Actor usernames
- IP addresses
- Metadata JSON

### Actions
- **View Details**: Modal showing full log entry with formatted metadata
- **Export to CSV**: Bulk export selected logs

### Features
- Auto-refresh every 30 seconds
- Sortable columns
- Pagination
- No create/edit/delete (read-only)

## API Endpoint

### GET `/api/admin/audit-logs`

**Authentication**: Admin-only (middleware: `auth`, `admin`)

**Query Parameters**:
- `action` (string|array): Filter by action(s)
- `target_type` (string): Filter by target type
- `target_id` (int): Filter by target ID
- `reason` (string|array): Filter by reason(s)
- `actor_id` (int): Filter by actor ID
- `date_from` (date): Start date (YYYY-MM-DD)
- `date_to` (date): End date (YYYY-MM-DD)
- `per_page` (int): Results per page (max 100, default 50)

**Response**:
```json
{
  "current_page": 1,
  "data": [
    {
      "id": 1,
      "action": "config_manual_disabled",
      "actor_type": "App\\Models\\User",
      "actor_id": 5,
      "target_type": "config",
      "target_id": 123,
      "reason": "admin_action",
      "request_id": null,
      "ip": "192.168.1.1",
      "user_agent": "Mozilla/5.0...",
      "meta": {
        "remote_success": true,
        "attempts": 1,
        "panel_id": 2
      },
      "created_at": "2025-11-01T15:30:00.000000Z",
      "updated_at": "2025-11-01T15:30:00.000000Z"
    }
  ],
  "first_page_url": "...",
  "from": 1,
  "last_page": 5,
  "last_page_url": "...",
  "next_page_url": "...",
  "path": "...",
  "per_page": 50,
  "prev_page_url": null,
  "to": 50,
  "total": 250
}
```

**Example Requests**:

```bash
# Get all logs from last 7 days
curl -H "Authorization: Bearer {token}" \
  "/api/admin/audit-logs?date_from=2025-10-25"

# Get all manual disable actions
curl -H "Authorization: Bearer {token}" \
  "/api/admin/audit-logs?action=config_manual_disabled"

# Get logs for specific config
curl -H "Authorization: Bearer {token}" \
  "/api/admin/audit-logs?target_type=config&target_id=123"

# Get failed remote operations
curl -H "Authorization: Bearer {token}" \
  "/api/admin/audit-logs?reason=traffic_exceeded&per_page=100"
```

## Sample Records

### Example 1: Manual Config Disable
```json
{
  "id": 42,
  "action": "config_manual_disabled",
  "actor_type": "App\\Models\\User",
  "actor_id": 7,
  "target_type": "config",
  "target_id": 156,
  "reason": "admin_action",
  "ip": "10.0.1.45",
  "meta": {
    "remote_success": true,
    "attempts": 1,
    "panel_id": 3,
    "panel_type_used": "marzban"
  },
  "created_at": "2025-11-01T10:15:30Z"
}
```

### Example 2: Auto Disable (Traffic Exceeded)
```json
{
  "id": 43,
  "action": "config_auto_disabled",
  "actor_type": null,
  "actor_id": null,
  "target_type": "config",
  "target_id": 157,
  "reason": "traffic_exceeded",
  "ip": null,
  "meta": {
    "remote_success": false,
    "attempts": 3,
    "last_error": "Connection timeout",
    "panel_id": 2,
    "panel_type_used": "marzneshin"
  },
  "created_at": "2025-11-01T12:00:00Z"
}
```

### Example 3: Reseller Suspension
```json
{
  "id": 44,
  "action": "reseller_suspended",
  "actor_type": null,
  "actor_id": null,
  "target_type": "reseller",
  "target_id": 12,
  "reason": "reseller_quota_exhausted",
  "ip": null,
  "meta": {
    "traffic_used_bytes": 10737418240,
    "traffic_total_bytes": 10737418240,
    "window_ends_at": "2025-12-01T00:00:00Z"
  },
  "created_at": "2025-11-01T14:30:15Z"
}
```

### Example 4: Reseller Recharged
```json
{
  "id": 45,
  "action": "reseller_recharged",
  "actor_type": "App\\Models\\User",
  "actor_id": 1,
  "target_type": "reseller",
  "target_id": 12,
  "reason": null,
  "ip": "192.168.1.100",
  "meta": {
    "old_traffic_bytes": 10737418240,
    "new_traffic_bytes": 21474836480,
    "added_bytes": 10737418240,
    "added_gb": 10.0
  },
  "created_at": "2025-11-01T15:00:00Z"
}
```

## Privacy & Security

### PII Policy
- **Never logged**: Passwords, API keys, credentials, session tokens
- **Logged**: User IDs, IP addresses, panel URLs (hostname only)
- **Sanitized**: Error messages (stack traces stripped)

### Access Control
- Audit logs are **admin-only**
- Policy: `AuditLogPolicy` restricts `viewAny` and `view` to admins
- API endpoint requires `auth` and `admin` middleware

### Retention
- No automatic deletion (manual cleanup if needed)
- Consider implementing TTL policy for compliance (e.g., 90 days)

## Integration Points

### Controllers
- `ConfigController`: `disable()`, `enable()`, `destroy()`
- `AuditLogsController`: API endpoint

### Jobs
- `SyncResellerUsageJob`: Auto-disable configs, reseller suspension
  - **Domain-specific audit logs**: Emits `config_auto_disabled` with reasons (`traffic_exceeded`, `time_expired`, `reseller_quota_exhausted`, `reseller_window_expired`)
  - **Domain-specific audit logs**: Emits `reseller_suspended` with reasons (`reseller_quota_exhausted`, `reseller_window_expired`)
  - Includes full remote operation telemetry: `remote_success`, `attempts`, `last_error`, `panel_id`, `panel_type_used`
- `ReenableResellerConfigsJob`: Auto-enable configs, reseller activation
  - **Domain-specific audit logs**: Emits `config_auto_enabled` with reason `reseller_recovered`
  - **Domain-specific audit logs**: Emits `reseller_activated` with reason `reseller_recovered`
  - Includes full remote operation telemetry

### Filament
- `ConfigsRelationManager`: Manual disable/enable/delete actions
- `AuditLogResource`: Admin UI for browsing logs

### Observers (Safety Net)
- `ResellerObserver`: Fallback logging for reseller changes
  - Creates audit logs with action matching status (`reseller_suspended`, `reseller_activated`)
  - Uses reason `audit_reseller_status_changed` to distinguish from domain-specific logs
  - **Note**: Jobs emit domain-specific logs with specific reasons; observer logs serve as safety net
- `ResellerConfigObserver`: Fallback logging for config changes

## Important: Domain-Specific vs Safety Net Audit Logs

The audit system has two layers:

1. **Domain-specific logs** (from jobs/controllers): These are the primary audit records
   - Created explicitly by business logic (e.g., `SyncResellerUsageJob`, `ReenableResellerConfigsJob`)
   - Include specific reason codes that explain *why* the action occurred
   - Examples: `config_auto_disabled` with reason `traffic_exceeded` or `reseller_quota_exhausted`
   - Include complete remote operation telemetry

2. **Safety net logs** (from observers): These are fallback records
   - Created automatically when model state changes
   - Use generic reason `audit_reseller_status_changed` or similar
   - Ensure no status change goes unlogged even if domain logic fails to create a log
   - Examples: `reseller_suspended` with reason `audit_reseller_status_changed`

**When troubleshooting**: Look for domain-specific logs first (specific reasons), then safety net logs if none exist.

## Troubleshooting with Audit Logs

### Scenario 1: Config Disabled Unexpectedly
**Steps**:
1. Go to Audit Logs
2. Filter by `target_type=config` and `target_id={config_id}`
3. Sort by `created_at` desc
4. Look for `config_auto_disabled` or `config_manual_disabled`
5. Check `reason` field - domain-specific logs will have specific reasons like `traffic_exceeded`, `reseller_quota_exhausted`, etc.
6. If multiple logs exist for the same action, look for the one with a specific reason (not `audit_*`)
7. Check `meta.remote_success` to see if remote panel operation succeeded

**Common Causes**:
- `traffic_exceeded`: Config hit its traffic limit
- `reseller_quota_exhausted`: Reseller out of quota (total usage across all configs exceeded limit)
- `reseller_window_expired`: Reseller window expired
- `time_expired`: Config expired due to time limit
- `admin_action`: Manually disabled by admin/user

**Note**: You may see multiple audit logs for the same status change:
- One from the job/controller with specific reason (e.g., `traffic_exceeded`)
- One from the observer with reason `audit_reseller_status_changed` or similar
- The domain-specific log (with specific reason) is the primary record to review

### Scenario 2: Remote Panel Operation Failed
**Steps**:
1. Filter by `meta.remote_success=false`
2. Check `meta.attempts` and `meta.last_error`
3. Verify panel connection in Panel Management

**Common Issues**:
- Connection timeout
- Invalid credentials
- Panel API changed

### Scenario 3: Reseller Suspended or Reactivated
**Steps**:
1. Filter by `target_type=reseller` and specific `target_id`
2. Look for `reseller_suspended` or `reseller_activated` actions
3. Check `reason` field for domain-specific reasons:
   - `reseller_quota_exhausted`: Total usage exceeded quota (sum of all config usage)
   - `reseller_window_expired`: Window validity period ended
   - `reseller_recovered`: Quota increased or window extended, configs auto-enabled
4. Review `meta` for usage details:
   - `traffic_used_bytes`: Total usage across all configs (including disabled ones)
   - `traffic_total_bytes`: Total allocated quota
   - `window_ends_at`: Window expiration timestamp

**Note**: Multiple logs may exist for the same suspension/activation:
- Domain-specific log with specific reason (e.g., `reseller_quota_exhausted`)
- Observer safety net log with reason `audit_reseller_status_changed`
- Focus on the domain-specific log for root cause analysis

## Best Practices

1. **Regular Monitoring**: Check audit logs daily for anomalies
2. **Failed Operations**: Investigate `remote_success=false` entries promptly
3. **Export & Archive**: Periodically export logs to external storage
4. **Alerting**: Consider setting up alerts for critical actions (e.g., bulk disables)
5. **Correlation**: Use `request_id` to trace multi-step operations

## Future Enhancements

- [ ] Automatic log rotation/archival
- [ ] Webhook notifications for critical events
- [ ] Advanced analytics dashboard
- [ ] Log streaming to external systems (Elasticsearch, Splunk)
- [ ] Automated anomaly detection
