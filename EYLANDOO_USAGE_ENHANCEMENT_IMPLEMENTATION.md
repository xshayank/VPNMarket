# Eylandoo Usage Parsing Enhancement - Implementation Summary

## Overview

This PR enhances the Eylandoo usage tracking system to be more resilient to various API response formats and provides comprehensive debugging capabilities to diagnose production issues.

## Problem Addressed

Even after prior fixes, Eylandoo usage remained at 0 in the reseller panel. Logs showed "no traffic data fields found". The system needed:
1. More flexible parsing to handle various API response shapes
2. Better diagnostics to see what the API actually returns
3. Tools for targeted debugging in production

## Changes Implemented

### 1. Enhanced Usage Parsing (`app/Services/EylandooService.php`)

Added `parseUsageFromResponse()` method with comprehensive support for:

**Multiple Wrapper Keys** (first match wins):
- `userInfo` (original)
- `data`
- `user`
- `result`
- `stats`

**Single Usage Fields** (7 variations):
- `total_traffic_bytes`
- `traffic_total_bytes`
- `total_bytes`
- `used_traffic`
- `data_used`
- `data_used_bytes`
- `data_usage_bytes`

**Upload + Download Pairs** (5 combinations):
- `upload_bytes` + `download_bytes`
- `upload` + `download`
- `up` + `down`
- `uploaded` + `downloaded`
- `uplink` + `downlink`

**Enhanced Features**:
- Safe handling of string numbers (converts to int)
- Treats `success: false` as hard failure (returns null)
- Detailed logging showing matched path (e.g., "userInfo.total_traffic_bytes")
- Lists available keys when no match found for debugging

### 2. Improved Job Diagnostics (`Modules/Reseller/Jobs/SyncResellerUsageJob.php`)

Enhanced `fetchEylandooUsage()` with:
- Log panel_id, panel URL before fetching
- Log resolved username (panel_user_id or external_username)
- Clear distinction between null (hard failure) and 0 (valid, no traffic)
- Context-rich error messages

### 3. New Debug Command (`app/Console/Commands/EylandooDebugUsage.php`)

```bash
php artisan eylandoo:debug-usage {username} [--panel-id=] [--panel-url=] [--api-token=]
```

**Features**:
- Fetches user data from panel
- Shows JSON response preview (first 500 chars)
- Displays normalized usage and matched path
- Lists available wrapper keys and fields
- Provides actionable error messages
- Supports testing with custom credentials

**Example Output**:
```
=== Eylandoo Usage Debug Tool ===

🔧 Using Panel: Eylandoo Production (ID: 123)
🌐 Panel URL: https://panel.example.com
👤 Username: test_user

📡 Calling GET /api/v1/users/test_user

✅ API Response received

📄 JSON Response Preview (first 500 chars):
------------------------------------------------------------
{
  "userInfo": {
    "username": "test_user",
    "total_traffic_bytes": 1073741824,
    "data_limit": 32,
    "is_active": true
  }
}
------------------------------------------------------------

✅ Usage parsed successfully

📊 Normalized Usage: 1073741824 bytes (1.00 GB)

🔍 Response Structure Analysis:
  ✓ Wrapper 'userInfo' found with keys: username, total_traffic_bytes, data_limit, is_active
  ℹ Top-level keys: userInfo

✅ Debug complete - check application logs for detailed parsing info
```

### 4. Comprehensive Test Coverage

**New Tests** (15 added):
- success:false handling
- All 5 wrapper keys (data, user, result, stats)
- All 7 single usage fields
- All 5 upload+download pair combinations
- String number handling
- Priority ordering verification

**Results**: 40 tests, 67 assertions, all passing

## Acceptance Criteria - Met ✅

- ✅ Enhanced usage parsing accepts multiple wrapper keys
- ✅ Recognizes 7 single usage fields and 5 upload+download pairs
- ✅ Safely casts string numbers
- ✅ Treats success:false as hard failure
- ✅ Logs matched path and available keys for debugging
- ✅ Endpoint and username logging confirmed
- ✅ Username resolution (panel_user_id fallback to external_username)
- ✅ Debug command implemented and functional
- ✅ All tests pass
- ✅ Code passes style checks

## Files Modified

1. `app/Services/EylandooService.php` - Enhanced getUserUsageBytes() with parseUsageFromResponse()
2. `Modules/Reseller/Jobs/SyncResellerUsageJob.php` - Enhanced logging and diagnostics
3. `app/Console/Commands/EylandooDebugUsage.php` - NEW debug command
4. `tests/Unit/EylandooServiceTest.php` - Added 15 comprehensive tests

## Testing

### Run Tests
```bash
php artisan test --filter=EylandooServiceTest
```

**Result**: 40 passed (67 assertions)

### Verify Command
```bash
php artisan list | grep eylandoo
# Output: eylandoo:debug-usage

php artisan eylandoo:debug-usage --help
```

### Manual Testing
```bash
# Test with a real panel
php artisan eylandoo:debug-usage resell_8_cfg_52 --panel-id=123

# Test with custom credentials
php artisan eylandoo:debug-usage test_user \
  --panel-url=https://panel.example.com \
  --api-token=your-token-here
```

## Deployment Steps

1. **Clear caches**
   ```bash
   php artisan optimize:clear
   ```

2. **Verify command registration**
   ```bash
   php artisan list | grep eylandoo
   ```

3. **Test with known config**
   ```bash
   php artisan eylandoo:debug-usage <username> --panel-id=<panel-id>
   ```

4. **Monitor logs**
   ```bash
   tail -f storage/logs/laravel.log | grep Eylandoo
   ```

5. **Trigger usage sync**
   ```bash
   php artisan schedule:run
   ```

6. **Verify in reseller UI**
   - Check that usage values are updating
   - Verify reseller totals reflect config usage

## Troubleshooting Guide

### Issue: Config still shows 0 usage

**Solution**:
```bash
# 1. Use debug command to see actual API response
php artisan eylandoo:debug-usage <username> --panel-id=<panel-id>

# 2. Check logs for parsing details
tail -f storage/logs/laravel.log | grep "Eylandoo usage"

# 3. Verify API credentials
# Check Panel record has valid url and api_token

# 4. Verify username mapping
# Ensure config.panel_user_id or config.external_username matches Eylandoo
```

### Issue: "No traffic data fields found"

This message now includes available keys. Check the logs to see what fields the API actually returns:

```bash
grep "available_keys" storage/logs/laravel.log | tail -5
```

If the API returns fields not yet supported, they can be easily added to the parser.

### Issue: Parse failed with success:false

The API is returning an error. Common causes:
- Invalid credentials
- User not found
- Permission denied

Check the raw API response with the debug command.

## Logging Reference

### Success Logs
```
[info] Eylandoo usage fetch: calling endpoint (username: test_user, endpoint: /api/v1/users/test_user)
[info] Eylandoo usage fetch starting (config_id: 123, panel_url: https://panel.example.com, username: test_user)
[info] Eylandoo usage parsed successfully (username: test_user, usage_bytes: 1073741824, matched_path: userInfo.total_traffic_bytes)
[info] Eylandoo usage for user test_user: 1073741824 bytes (config_id: 123)
```

### Error Logs
```
[warning] Eylandoo usage parse failed (username: test_user, reason: API returned success:false, available_keys: [success, message])
[warning] Eylandoo usage parse failed (username: test_user, reason: no traffic fields found, available_keys: {userInfo: [username, is_active]})
[warning] Eylandoo usage fetch failed for user test_user (hard failure - null returned) (config_id: 123, panel_url: https://panel.example.com)
```

## Security

- ✅ No new external dependencies
- ✅ API tokens handled securely via existing credential flow
- ✅ Input validation on command arguments
- ✅ Safe type casting for numeric values
- ✅ No information leakage in error messages
- ✅ CodeQL found no security issues

## Performance

- **Minimal overhead**: Only processes Eylandoo configs
- **Efficient parsing**: Stops at first match
- **Lazy logging**: Expensive operations only when needed
- **No schema changes**: Works with existing database structure

## Backward Compatibility

✅ All existing functionality preserved
✅ Existing response formats still work (userInfo.total_traffic_bytes)
✅ No breaking changes to API contracts
✅ Existing tests continue to pass

## Future Enhancements

If new API response formats are discovered:
1. Add new wrapper keys to `$wrapperKeys` array
2. Add new usage fields to `$singleKeys` or `$pairs` arrays
3. Add corresponding tests
4. No changes to calling code needed

## Next Steps

1. ✅ Deploy to production
2. Run debug command for sample configs showing 0 usage
3. Monitor logs for parsing details
4. Update parser if new field patterns discovered
5. Verify reseller UI reflects correct usage
