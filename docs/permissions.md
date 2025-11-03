# Permissions Documentation

This document describes the permissions used in the VpnMarket application.

## Permission Structure

Permissions follow a hierarchical naming convention: `resource.action` or `resource.action_scope`.

## Config Permissions

### Reset Usage Permissions

These permissions control who can reset the usage counter for reseller configs.

#### `configs.reset_usage`
- **Description**: Reset usage for any config (admin-level)
- **Assigned to**: `super-admin`, `admin`
- **Use case**: Allows admins to reset usage counters for any reseller config
- **Behavior**: 
  - Settles current `usage_bytes` into `meta.settled_usage_bytes`
  - Resets `usage_bytes` to 0
  - Updates `meta.last_reset_at` timestamp
  - Enforces 24-hour cooldown between resets
  - Attempts to reset usage on remote panel

#### `configs.reset_usage_own`
- **Description**: Reset usage for own configs (reseller-level)
- **Assigned to**: `reseller`
- **Use case**: Allows resellers to reset usage counters for their own configs
- **Behavior**: Same as `configs.reset_usage`, but scoped to configs belonging to the authenticated reseller

### Usage Reset Anti-Abuse Features

1. **Settlement Tracking**: Previous usage is preserved in `meta.settled_usage_bytes`, not lost
2. **Cooldown Period**: 24-hour minimum between resets per config (enforced in `ResellerConfig::canResetUsage()`)
3. **Aggregation**: Reseller total usage includes both `usage_bytes` + `settled_usage_bytes`
4. **Audit Trail**: Each reset is logged in:
   - `reseller_config_events` table (type: 'usage_reset')
   - `audit_logs` table (action: 'config_usage_reset')

### Policy Implementation

The `ResellerConfigPolicy::resetUsage()` method:
- Catches `PermissionDoesNotExist` exceptions gracefully
- Logs warnings if permissions are missing
- Returns `false` to deny access instead of throwing errors
- Allows access if user has appropriate permission and ownership

## Config Edit Permissions

### Time Limit Constraints

When editing config expiration dates:
- **Lower bound**: `expires_at >= today` (enforced in validation)
- **Midnight normalization**: All dates saved as start of day (00:00) in Asia/Tehran timezone
- **No upper bound**: Resellers can set expiration beyond their window (business decision)
- **Remote sync**: Changes propagate to panel via `ResellerProvisioner::updateUserLimits()`

## Installation

### Initial Setup

Run the RBAC seeder to create all permissions including reset usage:

```bash
php artisan db:seed --class=RbacSeeder
```

### Production Sync

Use the idempotent sync command to ensure permissions exist:

```bash
# Dry run to see what would change
php artisan permissions:sync --dry-run

# Apply changes
php artisan permissions:sync
```

### Standalone Seeder

To add only the reset usage permissions:

```bash
php artisan db:seed --class=PermissionsSeeder
```

## Troubleshooting

### Permission Not Found Error

If you see: `There is no permission named 'configs.reset_usage' for guard 'web'`

**Solution:**
```bash
php artisan permissions:sync
```

### Permission Cache Issues

After adding/modifying permissions:

```bash
# Clear permission cache
php artisan cache:clear

# If using Filament Shield
php artisan shield:cache
```

### Users Don't See New Permissions

Users must re-login after permissions are assigned to their role.

## Related Files

- **Policy**: `app/Policies/ResellerConfigPolicy.php`
- **Controller**: `Modules/Reseller/Http/Controllers/ConfigController.php`
- **Model**: `app/Models/ResellerConfig.php`
- **Seeders**: 
  - `database/seeders/RbacSeeder.php` (main permissions)
  - `database/seeders/PermissionsSeeder.php` (reset usage specific)
- **Command**: `app/Console/Commands/SyncPermissions.php`
- **Tests**:
  - `tests/Feature/ResellerConfigResetUsagePermissionTest.php`
  - `tests/Feature/ResellerConfigEditExpiryBeyondWindowTest.php`
