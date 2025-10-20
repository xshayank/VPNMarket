# Reseller Management Center - Manual Testing Guide

## Overview
This guide provides step-by-step instructions for manually testing the new Reseller Management Center in the admin panel.

## Prerequisites
- Admin user credentials
- At least one reseller with type 'traffic' and associated panel
- Sample reseller configs created

## Test Scenarios

### 1. Access Control
**Test**: Non-admin users cannot access the Reseller Management page
- [ ] Login with a regular user account (is_admin = false)
- [ ] Try to access `/admin/resellers`
- [ ] Expected: Access denied / redirect

**Test**: Admin users can access the Reseller Management page
- [ ] Login with an admin user account (is_admin = true)
- [ ] Navigate to `/admin/resellers`
- [ ] Expected: Page loads successfully

### 2. Stats Widget
**Test**: Stats widget displays correct information
- [ ] Navigate to Resellers list page
- [ ] Verify the following stats are displayed:
  - Total resellers count with active/suspended breakdown
  - Traffic usage (used GB) with total
  - Remaining traffic in GB
  - Active configs count
- [ ] Expected: All stats display accurate data

### 3. List View
**Test**: List columns display correctly
- [ ] Check that the following columns are visible:
  - ID
  - User (name and email)
  - Type (badge: پلن‌محور or ترافیک‌محور)
  - Status (badge: فعال or معلق)
  - Traffic (for traffic-based resellers only)
  - Window dates (for traffic-based resellers only)
  - Panel name and type (for traffic-based resellers only)
- [ ] Expected: All columns display correct data

### 4. Filters
**Test**: Type filter works
- [ ] Click on the Type filter
- [ ] Select "ترافیک‌محور"
- [ ] Expected: Only traffic-based resellers are shown
- [ ] Clear filter and select "پلن‌محور"
- [ ] Expected: Only plan-based resellers are shown

**Test**: Status filter works
- [ ] Click on the Status filter
- [ ] Select "فعال"
- [ ] Expected: Only active resellers are shown
- [ ] Select "معلق"
- [ ] Expected: Only suspended resellers are shown

**Test**: Panel Type filter works (if traffic-based resellers exist)
- [ ] Click on the Panel Type filter
- [ ] Select a panel type (e.g., "Marzban")
- [ ] Expected: Only resellers with that panel type are shown

### 5. Search
**Test**: Search by user email works
- [ ] Enter a user email in the search box
- [ ] Expected: Resellers with matching user email are shown

### 6. Reseller Actions

**Test**: Suspend action works
- [ ] Find an active reseller
- [ ] Click the "تعلیق" (Suspend) action
- [ ] Confirm the action
- [ ] Expected: 
  - Success notification appears
  - Reseller status changes to "معلق"
  - Status badge turns red

**Test**: Activate action works
- [ ] Find a suspended reseller
- [ ] Click the "فعال‌سازی" (Activate) action
- [ ] Confirm the action
- [ ] Expected:
  - Success notification appears
  - Reseller status changes to "فعال"
  - Status badge turns green

**Test**: Top-up Traffic action works
- [ ] Find a traffic-based reseller
- [ ] Click the "افزایش ترافیک" (Top-up Traffic) action
- [ ] Enter amount (e.g., 50 GB)
- [ ] Submit the form
- [ ] Expected:
  - Success notification appears
  - Traffic total increases by entered amount
  - Update is reflected in the list

**Test**: Extend Window action works
- [ ] Find a traffic-based reseller
- [ ] Click the "تمدید بازه" (Extend Window) action
- [ ] Enter number of days (e.g., 15)
- [ ] Submit the form
- [ ] Expected:
  - Success notification appears
  - Window end date extends by entered days
  - Update is reflected in the list

**Test**: View action works
- [ ] Click the "View" icon on any reseller
- [ ] Expected:
  - View page opens
  - All reseller details are displayed
  - Info sections show: General Info, Traffic Settings (if applicable), History

### 7. Bulk Actions

**Test**: Bulk suspend works
- [ ] Select multiple active resellers using checkboxes
- [ ] Click "Bulk actions" dropdown
- [ ] Select "تعلیق گروهی" (Bulk Suspend)
- [ ] Confirm the action
- [ ] Expected:
  - Success notification appears
  - All selected resellers change status to "معلق"

**Test**: Bulk activate works
- [ ] Select multiple suspended resellers using checkboxes
- [ ] Click "Bulk actions" dropdown
- [ ] Select "فعال‌سازی گروهی" (Bulk Activate)
- [ ] Confirm the action
- [ ] Expected:
  - Success notification appears
  - All selected resellers change status to "فعال"

### 8. Configs Relation Manager

**Test**: Access configs manager
- [ ] Find a traffic-based reseller
- [ ] Click the "کاربران" (Users) button
- [ ] Expected:
  - Redirects to reseller edit page
  - Configs relation manager is visible
  - List of configs is displayed

**Test**: Config list displays correctly
- [ ] Verify the following columns are visible:
  - ID
  - External Username (copyable)
  - Usage/Limit (with percentage)
  - Expires At (colored by status)
  - Status (badge)
  - Panel Type
- [ ] Expected: All columns display correct data

**Test**: Disable config action works
- [ ] Find an active config
- [ ] Click "غیرفعال" (Disable) action
- [ ] Confirm the action
- [ ] Expected:
  - Success notification appears
  - Config status changes to "disabled"
  - Config is disabled on the panel (check panel UI if possible)

**Test**: Enable config action works
- [ ] Find a disabled config
- [ ] Click "فعال" (Enable) action
- [ ] Confirm the action
- [ ] Expected:
  - Success notification appears
  - Config status changes to "active"
  - Config is enabled on the panel

**Test**: Reset Usage action works
- [ ] Find a config with non-zero usage
- [ ] Click "ریست مصرف" (Reset Usage) action
- [ ] Confirm the action
- [ ] Expected:
  - Success notification appears
  - Usage bytes reset to 0
  - Update is reflected in the list

**Test**: Extend Time action works
- [ ] Click "تمدید زمان" (Extend Time) action on any config
- [ ] Enter number of days (e.g., 10)
- [ ] Submit the form
- [ ] Expected:
  - Success notification appears
  - Expires at date extends by entered days
  - Panel is updated (verify if possible)

**Test**: Increase Traffic action works
- [ ] Click "افزایش ترافیک" (Increase Traffic) action on any config
- [ ] Enter amount in GB (e.g., 20)
- [ ] Submit the form
- [ ] Expected:
  - Success notification appears
  - Traffic limit increases by entered amount
  - Panel is updated (verify if possible)

**Test**: Delete config action works
- [ ] Click "حذف" (Delete) action on any config
- [ ] Confirm the action
- [ ] Expected:
  - Success notification appears
  - Config is soft deleted (status changes to "deleted")
  - Config is deleted from the panel
  - Config still exists in database (trashed)

**Test**: Config filters work
- [ ] Test Status filter (active/disabled/expired/deleted)
- [ ] Test Panel Type filter (Marzban/Marzneshin/X-UI)
- [ ] Expected: Filters work correctly

**Test**: Config bulk actions work
- [ ] Select multiple configs using checkboxes
- [ ] Test bulk disable
- [ ] Test bulk enable
- [ ] Test bulk delete
- [ ] Expected: All bulk actions work correctly

## Error Handling Tests

**Test**: Invalid input validation
- [ ] Try to top-up traffic with 0 or negative GB
- [ ] Try to extend window with 0 or negative days
- [ ] Try to extend time with invalid days
- [ ] Try to increase traffic with invalid GB
- [ ] Expected: Validation errors are displayed

**Test**: Panel connection failure handling
- [ ] Temporarily disable panel or use wrong credentials
- [ ] Try to disable/enable/delete a config
- [ ] Expected:
  - Error notification appears
  - Local DB operation may still succeed
  - Error is logged

## Performance Tests

**Test**: Stats widget polling
- [ ] Keep the resellers list page open
- [ ] Wait 60 seconds
- [ ] Expected: Stats refresh automatically

**Test**: Large dataset handling
- [ ] Create many resellers (100+) if possible
- [ ] Navigate to resellers list
- [ ] Test pagination
- [ ] Test filters with large dataset
- [ ] Expected: Page loads reasonably fast, pagination works

## Regression Tests

**Test**: Existing reseller functionality still works
- [ ] Test creating a new reseller
- [ ] Test editing a reseller
- [ ] Test existing reseller operations
- [ ] Expected: All existing functionality works as before

## Sign-off

- [ ] All critical tests passed
- [ ] All major features working correctly
- [ ] Error handling works as expected
- [ ] Performance is acceptable
- [ ] No regressions found

**Tested by**: _______________
**Date**: _______________
**Notes**: _______________
