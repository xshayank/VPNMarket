# Reseller Feature Documentation

## Overview

The Reseller feature allows administrators to convert regular users into resellers who can either:
1. **Plan-based resellers**: Purchase plans in bulk at discounted rates
2. **Traffic-based resellers**: Create unlimited VPN configs within their allocated traffic quota

## Architecture

### Database Structure

- **resellers**: Core reseller profile table
- **reseller_allowed_plans**: Junction table for plan-based reseller permissions
- **reseller_orders**: Bulk purchase orders from plan-based resellers
- **reseller_configs**: VPN configs created by traffic-based resellers
- **reseller_config_events**: Audit log for config lifecycle events
- **audit_logs**: Comprehensive audit trail for all reseller and config actions (see [AUDIT_LOGS.md](AUDIT_LOGS.md))

### Module Structure

```
Modules/Reseller/
├── Http/
│   ├── Controllers/
│   │   ├── DashboardController.php
│   │   ├── PlanPurchaseController.php
│   │   ├── ConfigController.php
│   │   └── SyncController.php
│   └── Middleware/
│       └── EnsureUserIsReseller.php (app/Http/Middleware/)
├── Jobs/
│   ├── ProvisionResellerOrderJob.php
│   └── SyncResellerUsageJob.php
├── Services/
│   ├── ResellerPricingService.php
│   └── ResellerProvisioner.php
├── Providers/
│   ├── ResellerServiceProvider.php
│   └── RouteServiceProvider.php
└── resources/
    └── views/
        ├── dashboard.blade.php
        ├── plans/index.blade.php
        ├── orders/show.blade.php
        └── configs/
```

## Admin Features

### 1. Convert User to Reseller

Navigate to **Admin Panel > Users** and click "Convert to Reseller" action on any user.

**Options:**
- **Type**: Plan-based or Traffic-based
- **Username Prefix**: Custom prefix for generated usernames (default: "resell")
- **For Traffic-based:**
  - Traffic quota in GB
  - Window duration in days
  - Allowed Marzneshin services (optional)

### 2. Manage Resellers

Navigate to **Admin Panel > Resellers**

**Features:**
- View all resellers
- Edit reseller settings
- Suspend/activate resellers
- Configure allowed plans (for plan-based resellers)
- Set per-reseller pricing overrides
- View and manage configs (for traffic-based resellers)
  - Monitor usage with progress bars showing percentage
  - View optional comments for config identification
  - Perform admin actions (disable, enable, extend, reset usage)

### 3. Configure Plan Visibility

Navigate to **Admin Panel > Plans** and edit any plan.

**Reseller Settings Section:**
- **Reseller Visible**: Toggle plan visibility to resellers
- **Reseller Price**: Fixed reseller price (optional)
- **Reseller Discount Percent**: Percentage discount from retail (optional)

### 4. Pricing Priority

When a reseller views a plan, pricing is calculated in this order:

1. Reseller-specific override (percent discount)
2. Reseller-specific override (fixed price)
3. Plan-level discount percent
4. Plan-level fixed reseller price
5. Plan hidden if none of the above are set

### 5. Attach Panel Configs to Reseller

Navigate to **Admin Panel > Management Resellers > Attach Panel Configs to Reseller**

This feature allows administrators to import existing configs from Marzban or Marzneshin panels that were created by non-sudo panel admins and attach them to a specific reseller.

**How it works:**

1. **Select Reseller**: Choose a reseller from the list. Only resellers using Marzban or Marzneshin panels are shown.
2. **Select Panel Admin**: After selecting a reseller, you'll see a list of non-sudo admins from that reseller's panel.
3. **Select Remote Configs**: Choose which configs/users created by that admin should be imported.
4. **Import**: The selected configs are imported as local ResellerConfig records without creating new users on the panel.

**Key features:**
- **Idempotency**: Duplicate configs (based on panel_id + panel_user_id or username) are automatically skipped.
- **Status mapping**: The remote config status is mapped to local status (active/disabled/expired).
- **Audit trail**: Each imported config generates:
  - A ResellerConfigEvent with type 'imported_from_panel'
  - An AuditLog entry with action 'config_imported_from_panel'
- **Traffic data**: Usage and limit information is imported from the panel if available.

**Limitations:**
- Only works with Marzban and Marzneshin panels (not XUI).
- Only non-sudo panel admins are shown in the list.
- The import does not provision new users on the panel; it only creates local records for existing remote users.

**Use cases:**
- Migrating configs from one reseller to another
- Importing configs that were manually created on the panel
- Consolidating panel users under reseller management

## Reseller Panel Features

### Plan-based Resellers

**Dashboard** (`/reseller`)
- View balance
- See total orders and accounts provisioned
- Recent order history

**Plans** (`/reseller/plans`)
- Browse available plans with reseller pricing
- Select quantity (max configurable via settings)
- Choose delivery mode:
  - **On-screen**: View accounts in browser
  - **Download**: Get CSV/JSON export

**Orders** (`/reseller/orders/{id}`)
- View order details
- Access provisioned accounts
- Download artifacts (usernames and subscription links)

### Traffic-based Resellers

**Dashboard** (`/reseller`)
- Traffic statistics (total, used, remaining)
- Window validity period
- Active configs count

**Configs** (`/reseller/configs`)
- List all created configs
- View usage per config
- View optional comments for easy identification
- Enable/disable configs
- Delete configs

**Create Config** (`/reseller/configs/create`)
- Select panel
- Set traffic limit
- Set expiry date (within reseller window)
- Add optional comment (max 200 characters) for identification
- Select allowed services (Marzneshin only, if whitelisted)

**Manual Sync** 
- Button to trigger immediate usage sync

## Username Convention

All provisioned usernames follow these patterns:

- **Plan-based**: `{prefix}_{resellerId}_order_{orderId}_{index}`
  - Example: `resell_1_order_42_1`
  
- **Traffic-based**: `{prefix}_{resellerId}_cfg_{configId}`
  - Example: `resell_1_cfg_123`

## Settings

Default settings can be configured via the `settings` table:

| Key | Default | Description |
|-----|---------|-------------|
| `reseller.username_prefix` | `resell` | Default prefix for usernames |
| `reseller.bulk_max_quantity` | `50` | Max accounts per bulk order |
| `reseller.configs_max_active` | `50` | Max active configs for traffic resellers |
| `reseller.usage_sync_interval_minutes` | `5` | Minutes between usage sync jobs |
| `reseller.allow_config_overrun` | `true` | Allow configs to exceed their own limits while reseller has quota |
| `reseller.auto_disable_grace_percent` | `2.0` | Grace percentage for reseller-level traffic enforcement |
| `reseller.auto_disable_grace_bytes` | `52428800` | Grace bytes (50MB) for reseller-level traffic enforcement |
| `reseller.time_expiry_grace_minutes` | `0` | Grace minutes after config expiration (0 = no grace) |
| `config.auto_disable_grace_percent` | `2.0` | Grace percentage for per-config traffic enforcement |
| `config.auto_disable_grace_bytes` | `52428800` | Grace bytes (50MB) for per-config traffic enforcement |

## Automated Enforcement

### Grace Thresholds

Grace thresholds prevent premature config disabling due to:
- API lag between usage sync and panel state
- Rounding errors in traffic calculations
- Temporary panel connectivity issues

**How it works:**
- System adds a grace buffer above configured limits
- Grace is the maximum of: `grace_bytes` or `limit * grace_percent / 100`
- Config is disabled only when: `usage >= limit + grace`

**Example:**
- Config limit: 5GB
- Grace percent: 2%
- Grace bytes: 50MB
- Effective enforcement limit: 5GB + max(102.4MB, 50MB) = 5.1GB

### Usage Sync Job

Runs every `reseller.usage_sync_interval_minutes`:

1. Fetches usage from panels for all active configs
2. Updates `usage_bytes` for each config
3. **Calculates total reseller usage from ALL configs (regardless of status)** - this ensures reseller-level suspension decisions are based on complete usage totals, not just active configs
4. Auto-disables configs that:
   - Exceed their individual traffic limit + grace (when `allow_config_overrun` is false)
   - Are past their expiry date + grace minutes
   - Belong to resellers whose quota + grace or window is exhausted
5. **Suspends reseller if total usage exceeds quota or window expires** - creates domain-specific audit logs with reason codes

**Important Note on Reseller Suspension:**
- Reseller total usage is calculated by summing `usage_bytes` from **all configs** (active, disabled, expired, etc.)
- This prevents the edge case where a config that was just disabled or is inactive could cause the reseller-level quota check to miss the actual overage
- When reseller is suspended, all remaining active configs are disabled with appropriate audit logs

**Enforcement Order:**
1. Attempt remote panel disable (with 3 retries: 0s, 1s, 3s delays)
2. Update local database status
3. Record event with telemetry
4. **Emit domain-specific AuditLog entries** with reason codes and remote operation telemetry

### Reseller Suspension

When a reseller is suspended:
- All active configs are disabled remotely and locally
- Access to `/reseller` panel is blocked
- No new purchases or configs can be created
- Rate limiting: 3 configs/sec with micro-sleeps (333ms between operations)

### Retry Logic

All remote panel operations use exponential backoff:
- **Attempt 1**: Immediate
- **Attempt 2**: 1 second delay
- **Attempt 3**: 3 seconds delay

Returns telemetry: `{success: bool, attempts: int, last_error: ?string}`

## Event Tracking

All config lifecycle events include detailed metadata:

### Event Types

| Event Type | Triggered By | Reason Codes |
|------------|-------------|--------------|
| `created` | User action | N/A |
| `auto_disabled` | System (SyncResellerUsageJob) | `traffic_exceeded`, `time_expired`, `reseller_quota_exhausted`, `reseller_window_expired` |
| `manual_disabled` | User action (ConfigController), Admin action (Filament) | `admin_action` (Filament), N/A (ConfigController) |
| `auto_enabled` | System (ReenableResellerConfigsJob) | `reseller_recovered` |
| `manual_enabled` | User action (ConfigController), Admin action (Filament) | `admin_action` (Filament), N/A (ConfigController) |
| `audit_status_changed` | System (Observer - audit safety net) | N/A |
| `deleted` | User action | N/A |

### Event Metadata Fields

All disable/enable events include:

```json
{
  "reason": "string",           // Why operation occurred (e.g., 'admin_action' for Filament actions)
  "remote_success": true,       // Whether remote panel operation succeeded
  "attempts": 1,                // Number of retry attempts (1-3)
  "last_error": null,           // Error message if operation failed
  "panel_id": 5,                // Panel ID used
  "panel_type_used": "marzneshin", // Resolved panel type (not stale config type)
  "user_id": 123                // User ID (manual operations from ConfigController only)
}
```

**Audit Status Changed Event**: This is a system-generated audit-only event created by the `ResellerConfigObserver` as a safety net. It ensures any status change is tracked in the database, even if the code path doesn't explicitly create a domain event. This event:
- Is **only created if no recent domain event** (`auto_disabled`, `manual_disabled`, `auto_enabled`, `manual_enabled`, `expired`) was recorded within the last 2 seconds
- **Does not affect enforcement decisions** - it's purely for auditability
- Includes metadata fields:
  ```json
  {
    "from_status": "active",    // Previous status
    "to_status": "disabled",    // New status
    "actor": "system",          // User ID if authenticated, otherwise 'system'
    "request_id": "...",        // Request ID if available
    "route": "...",             // Route name if available
    "ip": "...",                // Request IP if available
    "panel_id": 5,              // Panel ID if available
    "panel_type": "marzneshin"  // Panel type if available
  }
  ```
- Always logs at `notice` level for visibility (credentials sanitized)

**Note on Admin Actions**: When admins disable/enable configs through the Filament admin interface:
- Events use `type='manual_disabled'` or `type='manual_enabled'` (not 'disabled' or 'enabled')
- The `reason` field is set to `'admin_action'` to distinguish from user-initiated actions
- The `panel_type_used` is resolved from the `Panel` model (via `panel_id`), not from the config's `panel_type` field
- Full telemetry is included: `remote_success`, `attempts`, `last_error`, `panel_id`, `panel_type_used`
- Info logs are written on successful operations, warnings on failures

### Querying Events

```php
// Get last disable event for a config
$event = ResellerConfigEvent::where('reseller_config_id', $config->id)
    ->where('type', 'auto_disabled')
    ->latest()
    ->first();

// Check if remote disable succeeded
if ($event->meta['remote_success']) {
    // Panel was successfully disabled
}

// Get retry count
$retries = $event->meta['attempts'];

// Query audit events (for debugging silent status changes)
$auditEvents = ResellerConfigEvent::where('reseller_config_id', $config->id)
    ->where('type', 'audit_status_changed')
    ->get();
```

## Ticketing Integration

Tickets created from the reseller panel are automatically tagged with `source='reseller'` and can be filtered in the admin panel for isolated queue management.

## Security Considerations

- Reseller middleware ensures only authenticated resellers can access `/reseller`
- Suspended resellers are blocked at middleware level
- Policies enforce resellers only access their own orders/configs
- Rate limiting should be applied to config creation endpoints
- Balance checks prevent over-purchasing

## Testing

Run tests with:

```bash
php artisan test --filter=Reseller
```

Tests cover:
- Pricing resolution logic
- Dashboard access control
- Middleware enforcement
- Suspended reseller blocking

## Troubleshooting

**Q: Reseller can't see any plans**
- Ensure `reseller_visible` is enabled on plans
- Check that plan has pricing configured (either fixed price or discount percent)
- Verify reseller status is 'active'

**Q: Bulk order stuck in 'provisioning'**
- Check queue is running: `php artisan queue:work`
- Review job logs for errors
- Verify panel credentials are correct

**Q: Traffic sync not updating**
- Ensure sync job is scheduled
- Check panel API connectivity
- Review sync job logs

**Q: Config creation fails**
- Verify traffic quota is not exceeded
- Check expiry is within reseller window
- For Marzneshin, ensure selected services are whitelisted

**Q: Config unexpectedly disabled**
- **Check Audit Logs** (System > Audit Logs in admin panel)
- Filter by `target_type=config` and specific `target_id`
- Look for `config_auto_disabled` or `config_manual_disabled` actions
- Review `reason` field: `traffic_exceeded`, `reseller_quota_exhausted`, etc.
- Check `meta.remote_success` to see if remote panel operation succeeded
- See [AUDIT_LOGS.md](AUDIT_LOGS.md) for detailed troubleshooting steps

**Q: Reseller suspended automatically**
- **Check Audit Logs** for `reseller_suspended` action
- Review `reason`: `reseller_quota_exhausted` or `reseller_window_expired`
- Check `meta.traffic_used_bytes` vs `meta.traffic_total_bytes`
- Verify `window_ends_at` timestamp
- See [AUDIT_LOGS.md](AUDIT_LOGS.md) for recovery procedures

## Future Enhancements

- Reseller API for external integrations
- Automated reporting and analytics
- Multi-currency support
- Webhook notifications for provisioning events
- Reseller-specific branding options

## Related Documentation

- **[Audit Logs](AUDIT_LOGS.md)**: Comprehensive audit trail for troubleshooting and compliance
  - Track all reseller lifecycle events (created, suspended, activated, recharged)
  - Monitor config actions (manual/auto disable/enable)
  - Review remote panel operation telemetry
  - Filter by action type, reason, date range, and more
