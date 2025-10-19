# Reseller Module Bugfix Implementation Summary

## Overview
This PR addresses critical issues in the Reseller module rollout, focusing on four main areas:
1. Traffic-based reseller panel selection and Marzneshin services
2. User dropdown improvements in admin Create Reseller form
3. Plan-based reseller quantity field defaults and validation
4. Allowed plans repeater data population on edit

## Changes Made

### 1. Database Migrations

#### Added panel_id to resellers table
**File**: `database/migrations/2025_10_19_110100_add_panel_id_to_resellers_table.php`
- Added `panel_id` foreign key column to `resellers` table
- Allows traffic-based resellers to be assigned to a specific V2Ray panel
- Nullable to maintain backward compatibility
- On delete: sets to null (preserves reseller record)

#### Added default value for quantity in reseller_orders
**File**: `database/migrations/2025_10_19_110200_add_default_quantity_to_reseller_orders_table.php`
- Set default value of `1` for `quantity` column
- Prevents 500 errors when quantity is not specified
- Ensures data integrity at database level

### 2. Model Updates

#### Reseller Model
**File**: `app/Models/Reseller.php`
- Added `panel_id` to fillable attributes
- Added `panel()` relationship method (BelongsTo Panel)
- Enables Eloquent relationship access to assigned panel

### 3. Admin Resource Updates

#### ResellerResource Form
**File**: `app/Filament/Resources/ResellerResource.php`

**User Selection Improvements**:
- Changed from email-only display to name + email format: "Name (email)"
- Implemented custom search using `getSearchResultsUsing()`
- Searches both `name` and `email` fields simultaneously
- Added preload for quick initial selection
- Handles null names gracefully with fallback: "بدون نام (email)"
- Escapes LIKE wildcards (%, _) to prevent SQL pattern injection
- Limited results to 50 for performance
- Added helpful UI messages in Persian

**Traffic-Based Reseller Section**:
- Added panel selector with live updates
- Required field for traffic-based resellers
- Searchable and preloaded
- Displays associated Marzneshin services section conditionally

**Marzneshin Services Selection**:
- Nested section under traffic settings
- Only visible when selected panel type is 'marzneshin'
- Uses CheckboxList for multi-select
- Dynamically fetches services from MarzneshinService API
- Mirrors UX pattern from PlanResource
- Error handling for API failures

**Allowed Plans Repeater (Plan-Based Resellers)**:
- Fixed field name from `id` to `plan_id` for proper relationship hydration
- Changed pivot field names from `pivot.field` to just `field` for Filament 3 compatibility
- Added searchable and preload to plan selector
- Added distinct and disableOptionWhen to prevent duplicate plan selection
- Added itemLabel to show plan names in collapsed repeater items
- Added min/max value validation for override_value
- Set defaultItems(0) to start with empty repeater

### 4. Controller Updates

#### ConfigController
**File**: `Modules/Reseller/Http/Controllers/ConfigController.php`

**create() method**:
- Filters panels based on reseller's assigned `panel_id`
- If reseller has `panel_id`, only shows that panel
- Otherwise shows all active panels
- Maintains backward compatibility

**store() method**:
- Added validation to ensure reseller uses only their assigned panel
- Prevents unauthorized panel access
- Returns user-friendly error message

#### PlanPurchaseController
**File**: `Modules/Reseller/Http/Controllers/PlanPurchaseController.php`

**store() method**:
- Added custom validation messages in Persian
- Improved error clarity for quantity validation

### 5. View Updates

#### Plans Index View
**File**: `Modules/Reseller/resources/views/plans/index.blade.php`
- Changed label from "تعداد" to "مقدار" with required indicator (*)
- Added `required` attribute to quantity input
- Added error display for quantity validation failures
- Maintains default value of 1

### 6. Provider Updates

#### ViewServiceProvider
**File**: `app/Providers/ViewServiceProvider.php`
- Added check to skip in testing environment
- Prevents database connection errors during test runs
- Added try-catch for graceful error handling
- Maintains functionality in production

### 7. Tests

#### ResellerResourceTest
**File**: `tests/Feature/ResellerResourceTest.php`
- Tests panel_id relationship functionality
- Validates allowed plans use correct plan_id field
- Tests Marzneshin service IDs storage as array
- Validates panel filtering for traffic resellers
- All 4 tests passing

## Security Considerations

1. **SQL Injection Prevention**:
   - LIKE wildcard characters (%, _) are escaped in user search
   - All queries use Laravel's query builder with parameter binding

2. **Authorization**:
   - Panel selection restricted to assigned panel for resellers
   - Validation ensures resellers cannot use unauthorized panels

3. **Input Validation**:
   - All user inputs validated on both frontend and backend
   - Quantity must be integer >= 1
   - Service IDs validated as integers
   - Panel ID must exist in database

4. **Data Integrity**:
   - Foreign key constraints in migrations
   - Default values prevent null constraint violations
   - Relationship methods ensure referential integrity

## Testing

- Created 4 new tests validating core functionality
- All new tests pass
- ViewServiceProvider updated to support testing environment
- Some pre-existing test failures unrelated to this PR (Vite manifest)

## Backward Compatibility

- `panel_id` is nullable - existing resellers work without it
- Default quantity value prevents errors for existing code
- ConfigController gracefully handles resellers without panel_id

## Acceptance Criteria Met

✅ Traffic reseller form: panel selection works; Marzneshin services list appears only for marzneshin; persisted and enforced
✅ User selector: searches name/email and preloads recent users
✅ Quantity: default 1, required & validated; no 500 errors
✅ Editing reseller shows existing allowed plans and saves correctly

## Files Modified

1. `database/migrations/2025_10_19_110100_add_panel_id_to_resellers_table.php` (new)
2. `database/migrations/2025_10_19_110200_add_default_quantity_to_reseller_orders_table.php` (new)
3. `app/Models/Reseller.php`
4. `app/Filament/Resources/ResellerResource.php`
5. `Modules/Reseller/Http/Controllers/ConfigController.php`
6. `Modules/Reseller/Http/Controllers/PlanPurchaseController.php`
7. `Modules/Reseller/resources/views/plans/index.blade.php`
8. `app/Providers/ViewServiceProvider.php`
9. `tests/Feature/ResellerResourceTest.php` (new)

## Migration Instructions

1. Run migrations: `php artisan migrate`
2. No data migration needed - all changes are additive
3. Existing resellers will work with `panel_id = null`
4. Admin can assign panels to existing resellers via edit form

## Notes

- Persian language used throughout for consistency with existing codebase
- All form fields maintain RTL text direction support
- Error messages are user-friendly and in Persian
- Code follows Laravel and Filament best practices
