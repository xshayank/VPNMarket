# Eylandoo Provisioner Architecture - Deployment Documentation

## Overview

This PR implements a modular provisioner architecture to fix Eylandoo config re-enable issues and improve code maintainability across all panel types (Eylandoo, Marzban, Marzneshin, X-UI).

## What Changed

### New Architecture

**Before:**
- Single monolithic `ResellerProvisioner` with large switch statements
- Hard to test panel-specific logic
- Difficult to add new panel types

**After:**
- Modular architecture with panel-specific provisioners
- `ProvisionerInterface` defines the contract
- `ProvisionerFactory` resolves the correct provisioner based on panel type
- Each panel type has its own provisioner class with focused logic

### Files Added

1. **`app/Provisioners/ProvisionerInterface.php`**
   - Defines `enableConfig()` and `disableConfig()` methods
   - Return type: `['success' => bool, 'attempts' => int, 'last_error' => ?string]`

2. **`app/Provisioners/BaseProvisioner.php`**
   - Base class with shared retry logic
   - 3 attempts with exponential backoff (0s, 1s, 3s)
   - Handles transient network errors (500, 502, 503, 504)

3. **`app/Provisioners/EylandooProvisioner.php`**
   - Eylandoo-specific implementation
   - Uses correct API endpoint: `POST /api/v1/users/{username}/toggle`
   - Validates credentials (base_url, api_token)
   - Idempotent: checks current status before toggling
   - Comprehensive logging with context (config_id, reseller_id, panel_id)

4. **`app/Provisioners/MarzbanProvisioner.php`**
   - Marzban-specific implementation
   - Uses `status: active/disabled` field

5. **`app/Provisioners/MarzneshinProvisioner.php`**
   - Marzneshin-specific implementation
   - Uses dedicated enable/disable endpoints

6. **`app/Provisioners/XUIProvisioner.php`**
   - X-UI-specific implementation
   - Uses `enable: true/false` field

7. **`app/Provisioners/ProvisionerFactory.php`**
   - Factory to resolve provisioner based on panel type
   - Throws clear exception for unknown panel types

8. **`tests/Feature/EylandooProvisionerTest.php`**
   - Comprehensive test suite (7 tests, 19 assertions)
   - Tests API calls, retry logic, idempotency, error handling
   - Integration test with `ReenableResellerConfigsJob`

### Files Modified

1. **`Modules/Reseller/Services/ResellerProvisioner.php`**
   - `enableConfig()` now uses `ProvisionerFactory::forConfig($config)`
   - `disableConfig()` now uses `ProvisionerFactory::forConfig($config)`
   - Existing methods (`enableUser`, `disableUser`, etc.) remain unchanged for backward compatibility

## Required Panel Settings

### Eylandoo Panels

Panel settings stored in `panels` table must include:

```json
{
  "url": "https://panel.example.com",  // Base URL (required)
  "api_token": "your-api-key-here",     // API token (required)
  "extra": {
    "node_hostname": "https://node.example.com"  // Optional: override for subscription URLs
  }
}
```

**Accessing in code:**
```php
$credentials = $panel->getCredentials();
$baseUrl = $credentials['url'];           // Required
$apiToken = $credentials['api_token'];    // Required
$nodeHostname = $credentials['extra']['node_hostname'] ?? $credentials['node_hostname'] ?? '';  // Optional
```

### Config Requirements

Each `ResellerConfig` must have:
- `panel_id`: Foreign key to the panel
- `panel_user_id`: Username on the remote panel (used as API identifier)
- `panel_type`: Panel type string (e.g., 'eylandoo', 'marzban')

## Deployment Steps

### 1. Pre-Deployment Checklist

- [ ] Review all changes in the PR
- [ ] Ensure database has valid panel credentials for all panels
- [ ] Back up database before deployment
- [ ] Note current error rate from logs

### 2. Deployment

```bash
# Pull latest code
git pull origin <branch-name>

# Install/update dependencies (if needed)
composer install --no-dev --optimize-autoloader

# Clear caches
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear

# Run optimizations
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Restart queue workers to pick up new code
php artisan queue:restart
```

### 3. Post-Deployment Verification

**A. Verify Panel Credentials**

```bash
# Check that all panels have required credentials
php artisan tinker
```

```php
// In tinker:
App\Models\Panel::where('panel_type', 'eylandoo')->get()->each(function($panel) {
    $creds = $panel->getCredentials();
    echo "Panel {$panel->id} - url: " . (isset($creds['url']) ? '✓' : '✗') . 
         ", api_token: " . (isset($creds['api_token']) ? '✓' : '✗') . "\n";
});
```

**B. Test Re-Enable Flow**

1. Find a test reseller with suspended status
2. Manually trigger re-enable job:

```bash
php artisan tinker
```

```php
// In tinker:
$reseller = App\Models\Reseller::find(YOUR_RESELLER_ID);
Modules\Reseller\Jobs\ReenableResellerConfigsJob::dispatch($reseller->id);
```

3. Check logs:

```bash
tail -f storage/logs/laravel.log | grep -E "Eylandoo|enable|disable"
```

Expected log entries:
- `Attempting to re-enable config`
- `Eylandoo enable user result`
- `Remote enable result for config`
- `Config X re-enabled in DB`

**C. Monitor Error Rates**

```bash
# Check for errors in last hour
grep -i "failed to enable\|failed to disable" storage/logs/laravel-$(date +%Y-%m-%d).log | wc -l

# Check specific Eylandoo errors
grep -i "eylandoo.*failed\|eylandoo.*error" storage/logs/laravel-$(date +%Y-%m-%d).log
```

### 4. Rollback Procedure

If issues occur, rollback is straightforward:

```bash
# Revert to previous commit
git revert <commit-sha>

# OR checkout previous version
git checkout <previous-branch-or-tag>

# Re-deploy
composer install --no-dev --optimize-autoloader
php artisan config:clear
php artisan cache:clear
php artisan queue:restart
```

**Why rollback is safe:**
- New provisioner classes are isolated (no changes to database)
- Existing `enableUser`/`disableUser` methods remain unchanged
- Falls back to original behavior if factory throws exception

## Testing Evidence

### Unit/Integration Tests

```bash
php artisan test tests/Feature/EylandooProvisionerTest.php
```

**Results:**
- ✓ Factory returns correct provisioner for panel type
- ✓ Eylandoo provisioner makes correct API calls with proper headers
- ✓ Idempotent behavior (enabling already-enabled config succeeds)
- ✓ Retry logic handles transient errors (3 attempts)
- ✓ Clear error messages for missing credentials
- ✓ Disable operation works correctly
- ✓ Integration with `ReenableResellerConfigsJob`

**Total:** 7 tests, 19 assertions, all passing

### Regression Tests

```bash
php artisan test tests/Feature/ResellerConfigReenableTest.php
```

**Results:**
- ✓ Configs marked when disabled by reseller suspension
- ✓ Reenable job filters configs by meta marker
- ✓ Reenable job creates events and audit logs
- ✓ Reenable job accepts null reseller ID

**Total:** 4 tests, 25 assertions, all passing

## Monitoring Post-Deployment

### Key Metrics to Watch

1. **Re-enable Success Rate**
   ```bash
   # Count successful re-enables in last hour
   grep "Remote enable result.*remote_success.*true" storage/logs/laravel-$(date +%Y-%m-%d).log | wc -l
   
   # Count failures
   grep "Remote enable result.*remote_success.*false" storage/logs/laravel-$(date +%Y-%m-%d).log | wc -l
   ```

2. **API Call Patterns**
   ```bash
   # Check Eylandoo API calls
   grep "Eylandoo enable user result" storage/logs/laravel-$(date +%Y-%m-%d).log | tail -20
   ```

3. **Error Patterns**
   ```bash
   # Check for credential issues
   grep -i "missing credentials\|credentials.*failed" storage/logs/laravel-$(date +%Y-%m-%d).log
   
   # Check for retry exhaustion
   grep "All 3 attempts.*failed" storage/logs/laravel-$(date +%Y-%m-%d).log
   ```

### Expected Behavior

**Successful Re-Enable (Eylandoo):**
1. Log: `Attempting to re-enable config` with full context
2. Log: `Eylandoo enable user result` with success=true
3. Log: `Remote enable result for config X` with remote_success=true
4. Log: `Config X re-enabled in DB`
5. `ResellerConfigEvent` created with type='auto_enabled'
6. `AuditLog` created with action='config_auto_enabled'

**Handling Transient Errors:**
1. Attempt 1 fails → Log: `Attempt 1/3 to enable...failed`
2. Wait 1 second
3. Attempt 2 fails → Log: `Attempt 2/3 to enable...failed`
4. Wait 3 seconds
5. Attempt 3 succeeds → Log: `Eylandoo enable user result` with success=true

**Handling Missing Credentials:**
1. Log: `Cannot enable Eylandoo config X: Missing Eylandoo credentials`
2. Log includes diagnostic info: `has_url`, `has_api_token`
3. Returns with clear error message
4. Config status updated locally (to avoid stuck state)

## Troubleshooting

### Issue: Config not re-enabling on Eylandoo panel

**Diagnosis:**
```bash
# Check logs for specific config
grep "config_id.*YOUR_CONFIG_ID" storage/logs/laravel-$(date +%Y-%m-%d).log
```

**Common Causes:**

1. **Missing credentials**
   - Symptom: `Missing Eylandoo credentials`
   - Fix: Update panel in database with valid `url` and `api_token`

2. **Invalid API token**
   - Symptom: HTTP 401/403 in logs
   - Fix: Regenerate API token in Eylandoo panel, update database

3. **Network issues**
   - Symptom: `All 3 attempts...failed` with connection errors
   - Fix: Check network connectivity, firewall rules

4. **User doesn't exist on panel**
   - Symptom: HTTP 404 from Eylandoo API
   - Fix: Verify `panel_user_id` matches actual username on panel

### Issue: Configs stuck in disabled state

**Diagnosis:**
```bash
# Find configs that should be enabled but aren't
php artisan tinker
```

```php
// In tinker:
$configs = App\Models\ResellerConfig::where('status', 'disabled')
    ->whereRaw("JSON_EXTRACT(meta, '$.disabled_by_reseller_suspension') = TRUE")
    ->get();
    
foreach ($configs as $config) {
    echo "Config {$config->id} - Reseller {$config->reseller_id} - Panel {$config->panel_id}\n";
}
```

**Fix:**
```php
// Manually retry for specific config
$provisioner = new Modules\Reseller\Services\ResellerProvisioner();
$result = $provisioner->enableConfig($config);
print_r($result);
```

## Security Considerations

### API Credentials

- API tokens stored in `panels` table (encrypted at rest if using Laravel encryption)
- Never logged in plaintext (only presence checked: `has_api_token: true/false`)
- Credentials validated before API calls to fail fast

### Error Messages

- User-facing errors don't expose sensitive panel details
- Detailed diagnostic info only in server logs
- Logs include request context but redact sensitive headers

## Performance Implications

### Retry Logic

- Maximum 3 attempts per config
- Exponential backoff: 0s, 1s, 3s (total max delay: 4 seconds)
- Rate limiting: 3 operations per second (unchanged)

### Memory Usage

- Factory pattern uses minimal memory (provisioners are lightweight)
- Each provisioner instance ~1KB memory
- No caching of provisioner instances (created on-demand)

### Database Impact

- No new tables or migrations required
- Same number of database queries as before
- Existing indexes remain optimal

## Support Contact

For issues or questions during deployment:
- Check logs: `storage/logs/laravel-*.log`
- Review test output: `php artisan test tests/Feature/EylandooProvisionerTest.php`
- Verify panel credentials: See "Post-Deployment Verification" section above
