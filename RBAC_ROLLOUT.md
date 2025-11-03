# RBAC System Rollout Documentation

## Overview
This document provides step-by-step instructions for rolling out the new Role-Based Access Control (RBAC) system using Spatie/Permission and Filament Shield.

## Prerequisites
- Backup your database before proceeding
- Ensure all users are logged out or be prepared to force re-authentication
- Have a break-glass super-admin account credentials ready
- Review the current user access patterns

## Installation Steps

### 1. Install Dependencies (Already Done in Repository)
The following packages are already installed:
- `spatie/laravel-permission` (v6.22.0)
- `bezhansalleh/filament-shield` (v3.9.10)

### 2. Run Database Migrations
```bash
php artisan migrate --force
```

This will create the following tables:
- `roles`
- `permissions`
- `model_has_permissions`
- `model_has_roles`
- `role_has_permissions`

It will also add `is_super_admin` column to the `users` table.

### 3. Seed Roles and Permissions
```bash
php artisan db:seed --class=RbacSeeder --force
```

This seeder will:
- Create 4 roles: super-admin, admin, reseller, user
- Create 100+ permissions for panels, resellers, configs, users, orders, plans, settings, APIs, etc.
- Grant all permissions to super-admin and admin roles
- Grant limited permissions to reseller and user roles
- Automatically migrate existing users to appropriate roles based on:
  - `is_super_admin` → super-admin role
  - `is_admin` → admin role
  - Has `reseller` relationship → reseller role
  - Default → user role

### 4. Verify User Migration
```bash
# Dry run to see what would happen
php artisan rbac:migrate-users --dry-run

# Apply the migration if not done by seeder
php artisan rbac:migrate-users
```

The command will show a summary:
- Number of users migrated to each role
- Any errors encountered

### 5. Clear and Rebuild Cache
```bash
php artisan optimize:clear
php artisan shield:cache
composer dump-autoload -o
```

### 6. Restart Queue Workers
```bash
# Supervisord
sudo supervisorctl restart all

# Or if using Horizon
php artisan horizon:terminate
```

## Post-Installation Verification

### 1. Check Super Admin Access
Log in with a super-admin account and verify:
- Can access all Filament resources
- Can view all resellers and configs
- Can access all custom pages (Attach Panel Configs, Email Center, etc.)
- Can access API endpoints

### 2. Check Admin Access
Log in with an admin account and verify:
- Can access all Filament resources
- Can view all resellers and configs
- Can access all custom pages
- Can access API endpoints

### 3. Check Reseller Access
Log in with a reseller account and verify:
- Cannot access other resellers' data
- Can only view own configs in relation manager
- Can update own reseller record
- Cannot access admin-only pages
- API requests return only own data

### 4. Check Regular User Access
Log in with a regular user account and verify:
- Cannot access admin panel (should see 403 or redirect)
- Can access user dashboard
- Cannot view reseller or admin resources

## Role and Permission Matrix

### Super Admin (`super-admin`)
- Full access to everything
- Bypass all policy checks via `before()` method
- Can manage users, roles, and permissions

### Admin (`admin`)
- Access to all Filament resources
- Access to all custom pages
- Access to all API endpoints
- Can manage resellers, configs, users, orders, plans
- Cannot bypass policy checks (uses permission system)

### Reseller (`reseller`)
- View and update own reseller record only
- View, create, update, delete own configs only
- Cannot view other resellers' data
- Cannot access admin pages
- Limited API access (only own resources)

### User (`user`)
- View own configs only
- No access to admin panel
- Minimal API access (view own data)
- Cannot manage any resources

## Permission Naming Convention

### Resource Permissions (Shield-generated)
- `view_any_{resource}` - List resources
- `view_{resource}` - View single resource
- `create_{resource}` - Create new resource
- `update_{resource}` - Update resource
- `delete_{resource}` - Delete resource
- `restore_{resource}` - Restore soft-deleted resource
- `force_delete_{resource}` - Permanently delete resource
- `replicate_{resource}` - Duplicate resource
- `reorder_{resource}` - Reorder resources

### Custom Permissions
- `{resource}.{action}` - Custom permissions (e.g., `configs.view_own`)
- `page_{PageName}` - Page access (e.g., `page_AttachPanelConfigsToReseller`)
- `widget_{WidgetName}` - Widget visibility (e.g., `widget_StatsOverview`)
- `api.{resource}.{action}` - API endpoint access

## Ownership Enforcement

### Reseller Ownership
Resellers can only access resources where:
```php
$resellerConfig->reseller_id === auth()->user()->reseller->id
```

This is enforced in:
- Policies (before database queries)
- Query scopes (in Filament resources)
- API controllers (via middleware and policies)

### Panel Access Control
```php
// User model
public function canAccessPanel(Panel $panel): bool
{
    if ($panel->getId() === 'admin') {
        return $this->hasAnyRole(['super-admin', 'admin']);
    }
    return false;
}
```

## Troubleshooting

### Issue: Users Can't Access Admin Panel
**Solution:**
1. Check if user has correct role: `User::find($id)->roles`
2. Run: `php artisan shield:cache`
3. Clear browser cache and cookies
4. Check `canAccessPanel()` method in User model

### Issue: Permission Denied Errors
**Solution:**
1. Check if role has required permissions: `Role::findByName('admin')->permissions`
2. Re-run seeder: `php artisan db:seed --class=RbacSeeder --force`
3. Clear cache: `php artisan optimize:clear`

### Issue: Reseller Can See Other Resellers' Data
**Solution:**
1. Check policy `before()` method - super-admin check might be allowing access
2. Verify query scope in ResellerResource
3. Check if reseller_id is properly set on resources

### Issue: Super Admin Locked Out
**Solution:**
Use the break-glass admin account or:
```bash
php artisan tinker
>>> $user = User::find(1);
>>> $user->assignRole('super-admin');
>>> $user->save();
```

## Rollback Plan

If issues are encountered and rollback is needed:

### Option 1: Emergency Fallback
The system still respects the legacy `is_admin` flag as a fallback in several places. Temporarily disable RBAC by:
1. Revert User model changes
2. Revert middleware changes
3. Clear cache

### Option 2: Full Rollback
```bash
# Backup current database first!

# Rollback migrations
php artisan migrate:rollback --step=2

# Remove Spatie packages
composer remove spatie/laravel-permission bezhansalleh/filament-shield

# Clear cache
php artisan optimize:clear
```

## Security Considerations

### Break-Glass Access
- Always maintain at least one super-admin account
- Store credentials securely (password manager, vault)
- Document the account location for emergency access

### Permission Cache
- Permissions are cached for 24 hours by default
- Use `php artisan shield:cache` after permission changes
- Consider shorter cache time for development: `config/permission.php`

### API Security
- All API routes protected with `auth` and `admin` middleware
- Policy checks on individual resources
- Ownership verification on resource access
- Rate limiting recommended for public endpoints

### Audit Logging
- All policy authorization failures can be logged
- AuditLog model tracks all sensitive operations
- Review audit logs regularly for suspicious activity

## Testing Checklist

- [ ] Super admin can access everything
- [ ] Admin can manage all resources
- [ ] Reseller can only access own resources
- [ ] Regular user has minimal access
- [ ] API endpoints enforce authorization
- [ ] Query scoping prevents data leakage
- [ ] Public endpoints still work (e.g., OVPN token download)
- [ ] Migration command works correctly
- [ ] Cache clearing works
- [ ] Queue workers process jobs correctly

## Support and Maintenance

### Regular Maintenance
1. Review and audit user roles quarterly
2. Check for orphaned permissions after code changes
3. Regenerate Shield permissions after adding new resources:
   ```bash
   php artisan shield:generate --all --panel=admin
   ```
4. Update RbacSeeder if adding new permissions

### Adding New Roles
1. Create role: `Role::create(['name' => 'new-role', 'guard_name' => 'web'])`
2. Assign permissions to role
3. Update policies if needed
4. Add to canAccessPanel() if Filament access required
5. Update documentation

### Adding New Permissions
1. Add to RbacSeeder permissions array
2. Run seeder to create permission
3. Assign to appropriate roles
4. Update policies to check new permission
5. Clear cache

## References
- [Spatie Permission Documentation](https://spatie.be/docs/laravel-permission/v6)
- [Filament Shield Documentation](https://github.com/bezhanSalleh/filament-shield)
- [Laravel Authorization Documentation](https://laravel.com/docs/authorization)
