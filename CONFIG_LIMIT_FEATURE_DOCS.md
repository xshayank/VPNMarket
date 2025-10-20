# Config Creation Limit Feature

## Overview
This feature adds an optional "config creation limit" for traffic-based resellers that can be set in the admin panel. It also makes the reseller time limit (window) optional.

## Changes Summary

### 1. Database Schema
- **Migration**: Added `config_limit` column to `resellers` table
  - Type: `unsigned integer`, nullable
  - Default: `null` (unlimited)
  - Values: `null` or `0` = unlimited, `> 0` = limit

### 2. Model Updates
- **Reseller Model** (`app/Models/Reseller.php`)
  - Added `config_limit` to fillable array
  - Added `config_limit` integer cast
  - Updated `isWindowValid()` to treat `null` `window_ends_at` as unlimited (always valid)

### 3. Admin Panel (Filament)
- **UserResource** - "Convert to Reseller" Action
  - Added optional "Config limit" numeric field (visible for traffic type)
  - Made "Window days" field optional (can be left empty)
  - Helper text: "0 or empty = unlimited"
  
- **ResellerResource** - Create/Edit Forms
  - Added optional "Config limit" numeric field
  - Made "Window start/end dates" optional with helper text
  - Automatically converts `0` to `null` during save

- **ConfigsRelationManager**
  - Already correctly handles null `usage_bytes` with `?? 0` operator
  - Always displays usage/limit with percentage and progress bar

### 4. Config Creation Enforcement
- **ConfigController@store** (`Modules/Reseller/Http/Controllers/ConfigController.php`)
  - Added check before config creation:
    - If `config_limit` is set (not null and > 0):
      - Counts total configs (excluding soft-deleted)
      - Blocks creation if limit reached with error message
    - If `config_limit` is null or 0: no limit enforced
  - Updated window validation to only check if `window_ends_at` is set

## Usage

### Setting Config Limit
1. Navigate to Admin → Resellers
2. Create or edit a reseller
3. For traffic-based resellers:
   - Enter a number in "Config limit" field (e.g., 5)
   - Leave empty or set to 0 for unlimited

### Optional Time Window
1. When creating/editing a traffic-based reseller:
   - Leave "Window start/end dates" empty for unlimited time
   - Or set specific dates to enforce a time window

### Behavior
- **Unlimited (default)**: `config_limit = null` or `0`
  - Reseller can create any number of configs (within other limits)
- **Limited**: `config_limit = 5`
  - Reseller can create up to 5 configs total
  - 6th attempt will be rejected with error message
- **Time Window**:
  - If `window_ends_at` is `null`: no time restrictions
  - If `window_ends_at` is set: must be within window to create configs

## Testing
- 9 new tests added in `tests/Feature/ResellerConfigLimitTest.php`
- All tests passing (9 tests, 12 assertions)
- Covers:
  - config_limit field storage (integer and null)
  - isWindowValid() with null window_ends_at
  - Config creation up to limit
  - Soft-deleted configs don't count toward limit
  - Unlimited behavior (null and 0)

## Migration
```bash
php artisan migrate
```

## Rollback
```bash
php artisan migrate:rollback
```

## Breaking Changes
None. This is a backward-compatible addition:
- Existing resellers default to `config_limit = null` (unlimited)
- Existing behavior preserved for window validation
- No changes to existing config creation logic (only adds check)

## Security Considerations
✅ All input properly validated (numeric, minValue constraints)
✅ Type safety with integer casting in model
✅ No SQL injection risk (using Eloquent ORM)
✅ Authorization checks preserved (user-reseller relationship)
✅ Business logic handles edge cases (null, 0, negative values)
