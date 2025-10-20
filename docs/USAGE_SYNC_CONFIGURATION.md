# Usage Sync Configuration

## Overview

The reseller usage sync job now runs more frequently to provide better real-time tracking of traffic usage. Previously, the job ran every 15 minutes minimum. Now it can run every 1-5 minutes.

## Configuration

### Setting Key
`reseller.usage_sync_interval_minutes`

### Valid Values
- `1`: Sync every 1 minute (most frequent)
- `2`: Sync every 2 minutes
- `3`: Sync every 3 minutes
- `4`: Sync every 4 minutes
- `5`: Sync every 5 minutes (default)

### Default Behavior
If no setting is configured, the system defaults to 5 minutes.

### Invalid Values
- Values less than 1 are automatically adjusted to 1
- Values greater than 5 are automatically adjusted to 5
- Example: Setting it to `10` will result in the job running every 5 minutes
- Example: Setting it to `0` will result in the job running every 1 minute

## How to Configure

### Via Database (Settings Table)
```sql
INSERT INTO settings (key, value) VALUES ('reseller.usage_sync_interval_minutes', '3')
ON DUPLICATE KEY UPDATE value = '3';
```

### Via Application Code
```php
use App\Models\Setting;

Setting::setValue('reseller.usage_sync_interval_minutes', '3');
```

### Via Laravel Tinker
```bash
php artisan tinker
```

```php
Setting::setValue('reseller.usage_sync_interval_minutes', '3');
```

## How It Works

1. **Scheduler runs every minute**: The Laravel scheduler checks every minute if it's time to dispatch the usage sync job
2. **Modulo calculation**: The job dispatches only when `current_minute % interval === 0`
3. **Runtime configuration**: Changes take effect immediately without redeploying the application
4. **Job uniqueness**: The job implements `ShouldBeUnique` with a 5-minute window to prevent overlapping executions

## Examples

### Interval = 1 minute
Job runs at: 00, 01, 02, 03, 04, 05, ..., 59 (every minute)

### Interval = 2 minutes
Job runs at: 00, 02, 04, 06, 08, 10, ..., 58 (every 2 minutes)

### Interval = 3 minutes
Job runs at: 00, 03, 06, 09, 12, 15, ..., 57 (every 3 minutes)

### Interval = 4 minutes
Job runs at: 00, 04, 08, 12, 16, 20, ..., 56 (every 4 minutes)

### Interval = 5 minutes (default)
Job runs at: 00, 05, 10, 15, 20, 25, 30, 35, 40, 45, 50, 55 (every 5 minutes)

## Job Uniqueness & Overlap Prevention

The `SyncResellerUsageJob` implements `ShouldBeUnique` with `$uniqueFor = 300` (5 minutes). This means:

- If a job is already running or has run within the last 5 minutes, subsequent dispatches are automatically skipped
- This prevents multiple instances of the job from running simultaneously
- Protects against resource exhaustion if the job takes longer than the configured interval

## Testing

### Local Testing with Sync Queue
```bash
# Set queue connection to sync for immediate execution
# In .env:
QUEUE_CONNECTION=sync

# Set interval to 1 minute for testing
php artisan tinker
Setting::setValue('reseller.usage_sync_interval_minutes', '1');

# Run scheduler multiple times
php artisan schedule:run
# Wait 1 minute
php artisan schedule:run
# You should see the job dispatched each time
```

### Testing with Queue Workers
```bash
# Start a queue worker
php artisan queue:work

# In another terminal, trigger the scheduler
while true; do php artisan schedule:run; sleep 60; done

# Check logs to see job execution
tail -f storage/logs/laravel.log | grep "SyncResellerUsageJob"
```

### Testing Job Uniqueness
```bash
# Create a test to verify uniqueness
# Add a sleep(10) in the job's handle() method temporarily
# Dispatch the job twice quickly
php artisan tinker
SyncResellerUsageJob::dispatch();
SyncResellerUsageJob::dispatch();
# The second dispatch should be skipped due to uniqueness constraint
```

## Monitoring

The job logs important events:

```
[timestamp] Starting reseller usage sync
[timestamp] Syncing usage for reseller {id}
[timestamp] Reseller usage sync completed
```

## Performance Considerations

- **1-minute interval**: Best for high-traffic resellers where real-time monitoring is critical
- **3-minute interval**: Good balance between frequency and server load
- **5-minute interval**: Default, suitable for most use cases
- **Job timeout**: 600 seconds (10 minutes)
- **Max retries**: 2 attempts per job

## Troubleshooting

### Job not running
1. Verify the Laravel scheduler is running: `php artisan schedule:list`
2. Check the setting value: `Setting::get('reseller.usage_sync_interval_minutes')`
3. Ensure queue workers are running: `php artisan queue:work`

### Job running too frequently
Check if the interval is set correctly:
```php
Setting::get('reseller.usage_sync_interval_minutes');
```

### Jobs overlapping
The `ShouldBeUnique` constraint should prevent this. If you see overlaps:
1. Verify cache is working properly
2. Check `$uniqueFor` value in the job class
3. Review application logs for errors

## Migration from Old System

If you were using the old 15-minute minimum:
- The system automatically uses the new 5-minute default
- No manual intervention required
- Your existing `reseller.usage_sync_interval_minutes` setting will be respected but clamped to [1,5]
- Example: If you had it set to 30, it will now be clamped to 5

## Summary

✅ **Default**: 5 minutes (was 15 minutes)  
✅ **Minimum**: 1 minute (was 15 minutes)  
✅ **Maximum**: 5 minutes (new constraint)  
✅ **Runtime configurable**: Yes  
✅ **Overlap protection**: Yes (5-minute uniqueness window)  
✅ **No schema changes**: No database migrations required
