# Reseller Config Name Customization

## Overview

This feature allows resellers to customize the naming of their generated configs through two mechanisms:
1. **Prefix** - Resellers can set a custom prefix for their config names
2. **Custom Name** - Super admins can set a full custom name that completely overrides the automatic generator

## Permissions

Two new permissions have been added:

- `configs.set_prefix` - Allows setting a custom prefix (typically assigned to reseller role)
- `configs.set_custom_name` - Allows setting a full custom name (typically assigned to super-admin role)

## How It Works

### Default Behavior (No Customization)
When neither prefix nor custom name is provided, the system uses the existing name generation logic:
```
Format: {reseller_prefix}_{reseller_id}_cfg_{config_id}
Example: resell_5_cfg_123
```

### With Custom Prefix
When a reseller provides a custom prefix:
```
Format: {custom_prefix}_{reseller_id}_cfg_{config_id}
Example: myprefix_5_cfg_123
```

### With Custom Name
When a super admin provides a custom name, it completely replaces the generated name:
```
Format: {custom_name}
Example: vip_client_account_001
```

### Priority
If both prefix and custom name are provided, custom name takes priority and overrides the prefix.

## Validation Rules

### Prefix Field
- **Optional**
- **Max length**: 50 characters
- **Allowed characters**: Letters (a-z, A-Z), numbers (0-9), underscore (_), hyphen (-)
- **Pattern**: `/^[a-zA-Z0-9_-]+$/`

### Custom Name Field
- **Optional**
- **Max length**: 100 characters
- **Allowed characters**: Letters (a-z, A-Z), numbers (0-9), underscore (_), hyphen (-)
- **Pattern**: `/^[a-zA-Z0-9_-]+$/`

## Database Schema

Two new columns have been added to the `reseller_configs` table:

```sql
ALTER TABLE reseller_configs 
ADD COLUMN prefix VARCHAR(50) NULL AFTER comment,
ADD COLUMN custom_name VARCHAR(100) NULL AFTER prefix;
```

## Installation

1. Run the migration:
```bash
php artisan migrate
```

2. Run the permission seeder:
```bash
php artisan db:seed --class=ConfigPrefixCustomNamePermissionsSeeder
```

3. (Optional) If using Filament Shield, refresh the cache:
```bash
php artisan shield:cache
```

4. Assign permissions to roles as needed

## UI Changes

### Create Config Form
The config creation form now includes:

1. **Prefix field** - Visible to users with `configs.set_prefix` permission
   - Shows hint: "Final name: prefix_resellerId_cfg_configId"
   - Validates in real-time with HTML5 pattern attribute

2. **Custom Name field** - Visible to users with `configs.set_custom_name` permission
   - Shows hint: "This name completely replaces the automatic name"
   - Validates in real-time with HTML5 pattern attribute

## Code Changes

### Files Modified

1. **Database Migration**
   - `database/migrations/2025_11_04_072100_add_prefix_and_custom_name_to_reseller_configs_table.php`

2. **Model**
   - `app/Models/ResellerConfig.php` - Added `prefix` and `custom_name` to fillable fields

3. **Service**
   - `Modules/Reseller/Services/ResellerProvisioner.php` - Updated `generateUsername()` to support prefix and custom name

4. **Controller**
   - `Modules/Reseller/Http/Controllers/ConfigController.php` - Added validation and handling for new fields

5. **View**
   - `Modules/Reseller/resources/views/configs/create.blade.php` - Added UI fields with permission checks

6. **Seeder**
   - `database/seeders/ConfigPrefixCustomNamePermissionsSeeder.php` - Creates and assigns permissions

### Tests

Comprehensive test coverage has been added in:
- `tests/Feature/ResellerConfigPrefixCustomNameTest.php` (17 tests)

All tests verify:
- Config creation with custom prefix
- Config creation with custom name
- Config creation without customization (backward compatibility)
- Validation rules
- Username generation logic
- Traffic and time limits continue to work correctly

## API Usage Examples

### Creating a Config with Custom Prefix

```php
POST /reseller/configs

{
    "panel_id": 1,
    "traffic_limit_gb": 10,
    "expires_days": 30,
    "prefix": "myprefix",
    "comment": "Optional comment"
}

// Generated username: myprefix_5_cfg_123
```

### Creating a Config with Custom Name

```php
POST /reseller/configs

{
    "panel_id": 1,
    "traffic_limit_gb": 10,
    "expires_days": 30,
    "custom_name": "vip_client_001",
    "comment": "Optional comment"
}

// Generated username: vip_client_001
```

### Creating a Config Without Customization

```php
POST /reseller/configs

{
    "panel_id": 1,
    "traffic_limit_gb": 10,
    "expires_days": 30,
    "comment": "Optional comment"
}

// Generated username: resell_5_cfg_123 (default behavior)
```

## Backward Compatibility

This feature is fully backward compatible:
- Existing configs continue to work without modification
- When neither prefix nor custom name is provided, the system uses the original naming logic
- All traffic and time limit functionality remains unchanged
- No breaking changes to existing APIs or functionality

## Security Considerations

1. **Permission-based access** - Only users with appropriate permissions can use these features
2. **Input validation** - Strict regex validation prevents injection attacks
3. **Character whitelist** - Only safe characters (alphanumeric, underscore, hyphen) are allowed
4. **Length limits** - Maximum lengths prevent abuse

## Troubleshooting

### Prefix not appearing in generated name
- Verify the user has the `configs.set_prefix` permission
- Check that the prefix follows the validation rules (alphanumeric, underscore, hyphen only)

### Custom name not being used
- Verify the user has the `configs.set_custom_name` permission
- Check that the custom name follows the validation rules
- Ensure the custom name is unique and doesn't conflict with existing usernames

### Permission errors
- Run the permission seeder: `php artisan db:seed --class=ConfigPrefixCustomNamePermissionsSeeder`
- Clear the permission cache: `php artisan permission:cache-reset`
- If using Filament Shield: `php artisan shield:cache`
- Have users log out and log back in to refresh their permissions
