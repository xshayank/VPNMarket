# Eylandoo Nodes Selector for Resellers - Implementation Summary

## Overview
This document describes the complete implementation of the Eylandoo nodes selector feature for resellers in the VpnMarket application. This feature allows resellers to select specific Eylandoo nodes when creating configs, with admin-controlled restrictions.

## Problem Statement (RESOLVED)
1. ~~Resellers with Eylandoo panels could not select nodes when creating configs~~ ✅ **FIXED**
2. ~~The nodes field was not visible when no nodes were available~~ ✅ **FIXED**
3. Nodes needed to be sent to the Eylandoo API during user creation ✅ **IMPLEMENTED**

## Latest Update (v2)
**Date**: Current session  
**Change**: Made nodes field always visible for Eylandoo panels, even when empty

**Before:**
- Field only visible when `$eylandoo_nodes` array was not empty
- Users confused when field didn't appear

**After:**
- Field always visible for Eylandoo panels
- Shows helpful empty state message when no nodes available
- Clear communication about what happens without node selection

## Solution Architecture

### 1. Backend - Controller Layer
**File**: `Modules/Reseller/Http/Controllers/ConfigController.php`

#### `create()` Method (Lines 36-91)
Prepares data for the config creation form:

```php
// Fetch Eylandoo nodes for each Eylandoo panel, filtered by reseller's allowed nodes
foreach ($panels as $panel) {
    if ($panel->panel_type === 'eylandoo') {
        // Use cached method (5 minute cache)
        $allNodes = $panel->getCachedEylandooNodes();
        
        // If reseller has node whitelist, filter nodes
        if ($reseller->eylandoo_allowed_node_ids && !empty($reseller->eylandoo_allowed_node_ids)) {
            $allowedNodeIds = $reseller->eylandoo_allowed_node_ids;
            $nodes = array_filter($allNodes, function($node) use ($allowedNodeIds) {
                return in_array($node['id'], $allowedNodeIds);
            });
        } else {
            $nodes = $allNodes;
        }
        
        if (!empty($nodes)) {
            $eylandooNodes[$panel->id] = array_values($nodes);
        }
    }
}
```

**Key Features:**
- Fetches nodes from Panel model's cached method
- Filters by reseller's `eylandoo_allowed_node_ids` if set
- Organizes nodes by panel_id for multi-panel support
- Re-indexes array after filtering

#### `store()` Method (Lines 93-240)
Handles config creation with node validation:

```php
// Validation rules (Line 119-120)
'node_ids' => 'nullable|array',
'node_ids.*' => 'integer',

// Whitelist validation (Lines 150-162)
if ($panel->panel_type === 'eylandoo' && $reseller->eylandoo_allowed_node_ids) {
    $nodeIds = $request->node_ids ?? [];
    $allowedNodeIds = $reseller->eylandoo_allowed_node_ids;
    
    foreach ($nodeIds as $nodeId) {
        if (! in_array($nodeId, $allowedNodeIds)) {
            return back()->with('error', 'One or more selected nodes are not allowed for your account.');
        }
    }
}

// Store in config meta (Lines 196-199)
'meta' => [
    'node_ids' => $request->input('node_ids', []),
],

// Pass to provisioner (Line 216)
'nodes' => $request->input('node_ids', []),
```

**Key Features:**
- Validates node_ids as array of integers
- Enforces admin-configured node restrictions
- Stores selected nodes in config meta
- Passes nodes to provisioner

### 2. Frontend - View Layer
**File**: `Modules/Reseller/resources/views/configs/create.blade.php`

#### HTML Structure (Lines 119-130) - **UPDATED v2**
```blade
<!-- Eylandoo Nodes selection - ALWAYS VISIBLE for Eylandoo panels -->
<div id="eylandoo_nodes_field" class="mb-4 md:mb-6" style="display: none;">
    <label class="block text-xs md:text-sm font-medium mb-2 text-gray-900 dark:text-gray-100">
        نودهای Eylandoo (اختیاری)
    </label>
    <div class="space-y-3" id="eylandoo_nodes_container">
        <!-- Nodes will be populated dynamically based on selected panel -->
    </div>
    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400" id="eylandoo_nodes_helper">
        انتخاب نود اختیاری است. اگر هیچ نودی انتخاب نشود، کانفیگ بدون محدودیت نود ایجاد می‌شود.
    </p>
</div>
```

**Key Changes in v2:**
- ❌ Removed `@if (count($eylandoo_nodes) > 0)` wrapper
- ✅ Field now always rendered in DOM
- ✅ Added `id="eylandoo_nodes_helper"` to helper text for dynamic updates

#### JavaScript Logic (Lines 145-211) - **UPDATED v2**
```javascript
const eylandooNodesData = @json($eylandoo_nodes ?? []);
const eylandooNodesHelper = document.getElementById('eylandoo_nodes_helper');

function toggleConnectionsField() {
    const selectedOption = panelSelect.options[panelSelect.selectedIndex];
    const panelType = selectedOption.getAttribute('data-panel-type');
    const panelId = selectedOption.value;
    
    if (panelType === 'eylandoo') {
        // ALWAYS show nodes field for Eylandoo panels
        eylandooNodesField.style.display = 'block';
        
        // Populate nodes if available, otherwise show empty state message
        if (eylandooNodesData[panelId] && eylandooNodesData[panelId].length > 0) {
            populateEylandooNodes(eylandooNodesData[panelId]);
            eylandooNodesHelper.textContent = 'انتخاب نود اختیاری است...';
        } else {
            // Show empty state message
            eylandooNodesContainer.innerHTML = '<p class="text-sm text-gray-600 dark:text-gray-400 p-3 bg-gray-100 dark:bg-gray-700 rounded">هیچ نودی برای این پنل یافت نشد. کانفیگ بدون محدودیت نود ایجاد خواهد شد.</p>';
            eylandooNodesHelper.textContent = 'در صورت عدم وجود نود، کانفیگ با تمام نودهای موجود در پنل کار خواهد کرد.';
        }
    } else {
        eylandooNodesField.style.display = 'none';
    }
}

function populateEylandooNodes(nodes) {
    eylandooNodesContainer.innerHTML = '';
    
    nodes.forEach(function(node) {
        const label = document.createElement('label');
        label.className = 'flex items-center text-sm md:text-base text-gray-900 dark:text-gray-100 min-h-[44px] sm:min-h-0';
        
        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.name = 'node_ids[]';
        checkbox.value = node.id;
        checkbox.className = 'w-5 h-5 md:w-4 md:h-4 rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 ml-2';
        
        const span = document.createElement('span');
        span.textContent = node.name + ' (ID: ' + node.id + ')';
        
        label.appendChild(checkbox);
        label.appendChild(span);
        eylandooNodesContainer.appendChild(label);
    });
}
```

**Key Features (v2):**
- ✅ Field ALWAYS visible for Eylandoo panels (even when empty)
- ✅ Empty state shows helpful message in styled container
- ✅ Dynamic helper text based on node availability
- ✅ Consistent UX with Marzneshin services selector
- ✅ Mobile-responsive styling

**Visual Example:**

**Scenario 1: Panel WITH nodes**
```
┌─────────────────────────────────────────────────┐
│ نودهای Eylandoo (اختیاری)                      │
├─────────────────────────────────────────────────┤
│ ☐ Node US-1 (ID: 1)                             │
│ ☐ Node EU-2 (ID: 2)                             │
│ ☐ Node ASIA-3 (ID: 3)                           │
├─────────────────────────────────────────────────┤
│ انتخاب نود اختیاری است. اگر هیچ نودی           │
│ انتخاب نشود، کانفیگ بدون محدودیت نود ایجاد    │
│ می‌شود.                                          │
└─────────────────────────────────────────────────┘
```

**Scenario 2: Panel WITHOUT nodes (NEW in v2)**
```
┌─────────────────────────────────────────────────┐
│ نودهای Eylandoo (اختیاری)                      │
├─────────────────────────────────────────────────┤
│ ╔═══════════════════════════════════════════╗   │
│ ║ هیچ نودی برای این پنل یافت نشد. کانفیگ   ║   │
│ ║ بدون محدودیت نود ایجاد خواهد شد.         ║   │
│ ╚═══════════════════════════════════════════╝   │
├─────────────────────────────────────────────────┤
│ در صورت عدم وجود نود، کانفیگ با تمام نودهای    │
│ موجود در پنل کار خواهد کرد.                    │
└─────────────────────────────────────────────────┘
```
- Dynamically populates checkboxes based on selected panel
- Displays node name + ID (ID as fallback if name missing)
- Consistent UX with Marzneshin services selector
- Mobile-responsive styling

### 3. Provisioner Layer
**File**: `Modules/Reseller/Services/ResellerProvisioner.php`

#### `provisionEylandoo()` Method (Lines 272-352)
```php
// Accept both 'nodes' and 'node_ids' parameters
$nodes = $options['nodes'] ?? $options['node_ids'] ?? [];

// Prepare user data
$userData = [
    'username' => $username,
    'expire' => $expiresAt->timestamp,
    'data_limit' => $trafficLimit,
    'max_clients' => $maxClients,
];

// Only add nodes if the array is non-empty
if (! empty($nodes) && is_array($nodes)) {
    $userData['nodes'] = $nodes;
}

$result = $service->createUser($userData);
```

**Key Features:**
- Accepts both `nodes` and `node_ids` parameter names
- Only includes nodes in API payload if non-empty
- Forwards nodes array to Eylandoo API
- Logs provision results for debugging

### 4. Panel Model
**File**: `app/Models/Panel.php`

#### `getCachedEylandooNodes()` Method (Lines 81-134)
```php
public function getCachedEylandooNodes(): array
{
    if ($this->panel_type !== 'eylandoo') {
        return [];
    }

    $cacheKey = "panel:{$this->id}:eylandoo_nodes";
    
    return Cache::remember($cacheKey, 300, function () {
        try {
            $credentials = $this->getCredentials();
            
            if (empty($credentials['url']) || empty($credentials['api_token'])) {
                Log::warning("Eylandoo nodes fetch: Missing credentials");
                return [];
            }
            
            $service = new EylandooService(...);
            $nodes = $service->listNodes();
            
            return $nodes;
        } catch (\Exception $e) {
            Log::error("Failed to fetch Eylandoo nodes: " . $e->getMessage());
            return [];
        }
    });
}
```

**Key Features:**
- 5-minute cache per panel
- Validates credentials before API call
- Comprehensive error logging
- Returns empty array on failure (graceful degradation)

### 5. Reseller Model
**File**: `app/Models/Reseller.php`

#### Fields (Lines 27-28, 39-40)
```php
// In $fillable array
'marzneshin_allowed_service_ids',
'eylandoo_allowed_node_ids',

// In $casts array
'marzneshin_allowed_service_ids' => 'array',
'eylandoo_allowed_node_ids' => 'array',
```

**Key Features:**
- Stores admin-configured allowed node IDs as array
- Automatically casts to/from JSON in database

## Data Flow

### Config Creation Flow:
1. **Reseller visits create page**
   - Controller fetches all nodes for Eylandoo panels
   - Filters nodes by reseller's `eylandoo_allowed_node_ids`
   - Passes filtered nodes to view

2. **Reseller selects panel**
   - JavaScript detects panel type change
   - Shows nodes field if Eylandoo
   - Populates checkboxes with panel-specific nodes

3. **Reseller selects nodes and submits**
   - Controller validates node_ids array
   - Checks nodes against reseller's allowed list
   - Stores selected nodes in config meta
   - Passes nodes to provisioner

4. **Provisioner creates user**
   - Includes nodes in Eylandoo API payload
   - POST /api/v1/users with `nodes: [1, 2, 3]`
   - Returns result with subscription URL

### Admin Restriction Flow:
1. **Admin edits reseller**
   - Selects Eylandoo panel
   - Sees nodes list from panel
   - Selects subset of allowed nodes
   - Saves as `eylandoo_allowed_node_ids`

2. **Reseller creates config**
   - Controller filters nodes by allowed list
   - Only allowed nodes shown in UI
   - Validation prevents selecting disallowed nodes

## Testing

### Test Coverage
**Files**: 
- `tests/Feature/EylandooNodesTest.php` (18 tests)
- `tests/Feature/EylandooNodesAdminFormTest.php` (9 tests)
- `tests/Unit/ResellerProvisionerEylandooTest.php` (9 tests)

#### Test Cases (34 tests total, 105 assertions):
1. ✅ Eylandoo service can list nodes
2. ✅ Panel model caches nodes (5 minutes)
3. ✅ Reseller config create shows filtered nodes
4. ✅ Config create includes nodes in provision request
5. ✅ Reseller cannot select nodes outside whitelist
6. ✅ Reseller without whitelist can use all nodes
7. ✅ Cache can be cleared and refreshed
8. ✅ Gracefully handles API errors
9. ✅ Non-Eylandoo panel returns empty nodes
10. ✅ Handles nodes without name field (ID fallback)
11. ✅ Handles various API response formats
12. ✅ Name field prioritization
13. ✅ Admin form displays nodes correctly
14. ✅ Missing credentials handling
15. ✅ Provisioner accepts node_ids parameter
16. ✅ Provisioner omits nodes when empty
17. ... and more

### Running Tests:
```bash
# Run all Eylandoo nodes related tests
php artisan test tests/Feature/EylandooNodesTest.php tests/Feature/EylandooNodesAdminFormTest.php tests/Unit/ResellerProvisionerEylandooTest.php

# Results:
# Tests:  2 skipped, 34 passed (105 assertions)
# Duration: 3.43s
```

## Security Considerations

1. **Node Whitelist Enforcement**
   - Server-side validation prevents bypassing restrictions
   - Error message doesn't leak allowed node IDs
   
2. **Input Validation**
   - node_ids validated as array of integers
   - Empty/missing nodes handled gracefully
   
3. **Credential Protection**
   - No credentials logged
   - API errors don't expose internals
   
4. **Cache Security**
   - Cache key includes panel ID (no cross-panel leakage)
   - Cache expiry prevents stale data

## Error Handling

### Graceful Failures:
- **Empty node list**: Field shows but with no options
- **API failure**: Logged, returns empty array
- **Missing credentials**: Logged warning, returns empty array
- **Invalid panel type**: Returns empty array
- **Cache miss**: Re-fetches from API

### User Feedback:
- **Disallowed node**: "One or more selected nodes are not allowed"
- **Empty nodes**: Helper text explains it's optional
- **Provision failure**: Generic error message

## Performance

### Caching Strategy:
- **Cache Duration**: 5 minutes per panel
- **Cache Key**: `panel:{panel_id}:eylandoo_nodes`
- **Cache Miss**: Single API call to fetch nodes
- **Cache Hit**: Instant response from memory

### Impact:
- **Page Load**: Minimal (cached data)
- **Form Submission**: Validation O(n×m) where n=submitted nodes, m=allowed nodes
- **API Calls**: 1 per panel per 5 minutes

## Acceptance Criteria ✅

All acceptance criteria from problem statement are met:

- [x] Reseller config create page shows "Nodes" multi-select for Eylandoo
- [x] Populated from `/api/v1/nodes` API with name fallback to ID
- [x] Similar UX to Marzneshin services selector
- [x] Selected node IDs sent to Eylandoo API as `nodes` array
- [x] Admin-restricted nodes enforced
- [x] Graceful handling of empty lists and errors
- [x] Clear logging for failures

## Verification Steps

### As Admin:
1. Navigate to Admin → Resellers → Edit
2. Select an Eylandoo panel
3. Set `eylandoo_allowed_node_ids` (e.g., [1, 2])
4. Save reseller

### As Reseller:
1. Navigate to Configs → Create
2. Select Eylandoo panel
3. See "نودهای Eylandoo" field with 2 nodes
4. Select nodes (optional)
5. Fill other fields and submit
6. Verify config created successfully

### Verification:
1. Check logs for create-user API call
2. Verify `nodes` array in API payload
3. Check Eylandoo panel that user has selected nodes
4. Confirm config stored with nodes in meta

## Files Modified

1. **Modules/Reseller/Http/Controllers/ConfigController.php**
   - Added node fetching in create()
   - Added node validation in store()
   - Pass nodes to provisioner

2. **Modules/Reseller/resources/views/configs/create.blade.php**
   - Added nodes field HTML
   - Added JavaScript for dynamic population

3. **Modules/Reseller/Services/ResellerProvisioner.php**
   - Updated provisionEylandoo() to accept nodes
   - Forward nodes to API

4. **app/Models/Panel.php**
   - Added getCachedEylandooNodes() method

5. **app/Models/Reseller.php**
   - Added eylandoo_allowed_node_ids field

## Future Enhancements

1. **Real-time Node Status**: Show online/offline indicator
2. **Bulk Node Assignment**: Apply nodes to multiple configs
3. **Node Usage Stats**: Show which nodes are most used
4. **Manual Cache Refresh**: Button to force node list update
5. **Node Groups**: Allow admins to create node groups
6. **Load Balancing**: Auto-select least-used nodes

## Troubleshooting

### Nodes Not Appearing:
1. Check panel credentials in admin
2. Verify panel_type is 'eylandoo'
3. Check Laravel logs for API errors
4. Clear cache: `php artisan cache:clear`
5. Test API directly with curl

### Validation Errors:
1. Verify reseller has allowed nodes set
2. Check submitted node IDs match allowed list
3. Ensure node_ids is array of integers

### Provision Failures:
1. Check logs for API response
2. Verify Eylandoo API accepts nodes parameter
3. Test with empty nodes array (should work)

## Conclusion

The Eylandoo nodes selector feature is fully implemented, tested, and ready for production. It provides a seamless experience for resellers while maintaining admin control over node access. The implementation follows Laravel best practices, includes comprehensive error handling, and is backed by extensive test coverage.
