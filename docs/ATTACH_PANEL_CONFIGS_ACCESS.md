# Attach Panel Configs to Reseller - Access Configuration

## Overview
This document describes the setup and access control for the "Attach Panel Configs to Reseller" admin page.

## Page Location
- **URL**: `/admin/attach-panel-configs-to-reseller`
- **Navigation**: Admin Panel → مدیریت فروشندگان (Reseller Management)
- **Icon**: heroicon-o-link

## Access Control

The page supports multiple authorization methods with automatic fallback:

### 1. Spatie Permission (if installed)
Users with the permission `manage.panel-config-imports` can access the page.

### 2. Spatie Roles (if installed)
Users with roles `super-admin` or `admin` can access the page.

### 3. Simple Admin Flag (default/fallback)
Users with `is_admin = true` in the database can access the page.

## Setup Instructions

### For New Installations

1. **Install dependencies** (if not already done):
   ```bash
   composer install
   php artisan key:generate
   ```

2. **Run database migrations**:
   ```bash
   php artisan migrate
   ```

3. **Create an admin user** (via tinker or seeder):
   ```bash
   php artisan tinker
   ```
   ```php
   User::create([
       'name' => 'Admin User',
       'email' => 'admin@example.com',
       'password' => Hash::make('password'),
       'is_admin' => true,
   ]);
   ```

### If Using Spatie Permission Package

1. **Install Spatie Permission** (optional):
   ```bash
   composer require spatie/laravel-permission
   php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
   php artisan migrate
   ```

2. **Run the permission seeder**:
   ```bash
   php artisan db:seed --class=AttachPanelConfigsPermissionSeeder
   ```

3. **If using Filament Shield**, refresh the cache:
   ```bash
   php artisan shield:cache
   ```

### Post-Installation Verification

1. **Clear application cache**:
   ```bash
   php artisan optimize:clear
   composer dump-autoload -o
   ```

2. **Re-login** to refresh user permissions

3. **Test access** to `/admin/attach-panel-configs-to-reseller`:
   - Admin users should see the page in navigation
   - Non-admin users should get 403 Forbidden
   - Page should load without errors for authorized users

## Verification Checklist

After setup, verify the following:

- [ ] `composer dump-autoload -o` completed successfully
- [ ] `php artisan optimize:clear` completed successfully
- [ ] `php artisan db:seed --class=AttachPanelConfigsPermissionSeeder` completed (if using Spatie)
- [ ] Admin user can see "اتصال کانفیگ‌های پنل به ریسلر" in navigation
- [ ] Admin user can access `/admin/attach-panel-configs-to-reseller` without 403
- [ ] Non-admin user gets 403 when accessing the page
- [ ] Other admin pages still work correctly (no regressions)

## Troubleshooting

### 403 Forbidden Error

1. **Check user admin status**:
   ```bash
   php artisan tinker
   ```
   ```php
   User::find($userId)->is_admin; // Should be true
   ```

2. **Clear caches**:
   ```bash
   php artisan optimize:clear
   php artisan config:clear
   php artisan cache:clear
   ```

3. **Verify panel configuration**:
   - Check `app/Providers/Filament/AdminPanelProvider.php`
   - Ensure `authGuard('web')` is set
   - Verify page is in `->pages([...])` array

4. **If using Spatie Permission**:
   ```bash
   php artisan tinker
   ```
   ```php
   $user = User::find($userId);
   $user->hasPermissionTo('manage.panel-config-imports'); // Should be true for admins
   ```

### Page Not in Navigation

1. Check that `shouldRegisterNavigation()` returns true
2. Verify the user passes `canAccess()` check
3. Clear Filament cache if applicable

## Technical Details

### Files Modified
- `app/Filament/Pages/AttachPanelConfigsToReseller.php`: Added `getSlug()`, `shouldRegisterNavigation()`, and enhanced `canAccess()`
- `app/Providers/Filament/AdminPanelProvider.php`: Added explicit `authGuard('web')` configuration
- `database/seeders/AttachPanelConfigsPermissionSeeder.php`: New seeder for permission setup

### Key Methods

**`getSlug()`**: Returns `'attach-panel-configs-to-reseller'` for consistent routing

**`shouldRegisterNavigation()`**: Shows page in navigation only if user has access

**`canAccess()`**: Multi-layered access control:
1. Checks Spatie permission `manage.panel-config-imports`
2. Checks Spatie roles `super-admin` or `admin`
3. Falls back to `is_admin` boolean field

### Guard Configuration
The admin panel uses the `web` guard (session-based authentication) as defined in `config/auth.php`.

## Support

For issues or questions:
1. Check the troubleshooting section above
2. Verify all verification checklist items
3. Review Laravel and Filament logs in `storage/logs/`
