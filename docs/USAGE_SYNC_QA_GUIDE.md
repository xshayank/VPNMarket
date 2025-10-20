# Manual QA Guide: Usage Sync Frequency Improvements

## Quick Test Steps

### 1. Verify Default Interval (5 minutes)

```bash
# In Laravel Tinker
php artisan tinker

# Check default setting
Setting::get('reseller.usage_sync_interval_minutes')
# Should return null (not set) or '5'

# If null, verify it uses 5 as default
Setting::getInt('reseller.usage_sync_interval_minutes', 5)
# Should return 5
```

### 2. Test 1-Minute Interval (Most Frequent)

```bash
# Set interval to 1 minute
php artisan tinker
Setting::setValue('reseller.usage_sync_interval_minutes', '1');

# Run scheduler multiple times
php artisan schedule:run
# Wait 60 seconds
php artisan schedule:run
# Wait 60 seconds  
php artisan schedule:run

# Check logs - should see "Scheduling SyncResellerUsageJob (interval: 1 minutes)" every time
tail -f storage/logs/laravel.log | grep "SyncResellerUsageJob"
```

### 3. Test Interval Clamping (Minimum)

```bash
php artisan tinker

# Test value below minimum (0 -> should clamp to 1)
Setting::setValue('reseller.usage_sync_interval_minutes', '0');
$interval = Setting::getInt('reseller.usage_sync_interval_minutes', 5);
# Applying clamping logic: if ($interval < 1) $interval = 1;
# Result should be 1

# Test negative value (-10 -> should clamp to 1)
Setting::setValue('reseller.usage_sync_interval_minutes', '-10');
# After clamping logic, should result in 1
```

### 4. Test Interval Clamping (Maximum)

```bash
php artisan tinker

# Test value above maximum (10 -> should clamp to 5)
Setting::setValue('reseller.usage_sync_interval_minutes', '10');
# After clamping logic: if ($interval > 5) $interval = 5;
# Result should be 5

# Test very high value (100 -> should clamp to 5)
Setting::setValue('reseller.usage_sync_interval_minutes', '100');
# After clamping logic, should result in 5
```

### 5. Test Job Uniqueness (No Overlapping Runs)

```bash
# Temporarily modify the job to add a sleep for testing
# Edit Modules/Reseller/Jobs/SyncResellerUsageJob.php
# In handle() method, add at the beginning: sleep(120); // 2 minutes

# Set interval to 1 minute
php artisan tinker
Setting::setValue('reseller.usage_sync_interval_minutes', '1');

# Start a queue worker in one terminal
php artisan queue:work

# In another terminal, trigger scheduler every minute
php artisan schedule:run
sleep 60
php artisan schedule:run

# Check logs - should see:
# - First job starts and processes
# - Second dispatch is skipped due to uniqueness constraint
# - After 5 minutes ($uniqueFor), job can run again

# Remember to remove the sleep() after testing!
```

### 6. Test with Real Reseller Data

```bash
# Create test reseller and configs (if needed)
php artisan tinker

$panel = Panel::create([
    'name' => 'Test Panel',
    'url' => 'https://example.com',
    'panel_type' => 'marzban',
    'username' => 'admin',
    'password' => 'password',
    'is_active' => true,
]);

$reseller = Reseller::factory()->create([
    'type' => 'traffic',
    'status' => 'active',
    'traffic_total_bytes' => 10 * 1024 * 1024 * 1024,
]);

# Set interval to 1 minute for testing
Setting::setValue('reseller.usage_sync_interval_minutes', '1');

# Watch logs while scheduler runs
tail -f storage/logs/laravel.log | grep -E "Starting reseller usage sync|Syncing usage for reseller"
```

### 7. Verify Scheduler Configuration

```bash
# View all scheduled commands
php artisan schedule:list

# Should show the reseller usage sync task running every minute
```

### 8. Test Runtime Configuration Changes

```bash
# Set interval to 3
php artisan tinker
Setting::setValue('reseller.usage_sync_interval_minutes', '3');
exit

# Run scheduler at minute 0, 3, 6 (should dispatch)
# Run scheduler at minute 1, 2, 4, 5 (should NOT dispatch)

# Change to interval 2 without restarting
php artisan tinker
Setting::setValue('reseller.usage_sync_interval_minutes', '2');
exit

# Now runs at minute 0, 2, 4, 6, 8, 10, etc.
# Verify logs show new interval
```

## Expected Log Output Examples

### Successful Dispatch (Interval: 1 minute)
```
[2025-10-20 12:30:00] Scheduling SyncResellerUsageJob (interval: 1 minutes)
[2025-10-20 12:30:00] Starting reseller usage sync
[2025-10-20 12:30:05] Syncing usage for reseller 1
[2025-10-20 12:30:10] Reseller usage sync completed
```

### Skipped Dispatch (Not on interval boundary)
```
# No log entry when current_minute % interval !== 0
```

### Job Uniqueness Prevention
```
# First dispatch
[2025-10-20 12:30:00] Starting reseller usage sync

# Second dispatch within 5 minutes (skipped silently by Laravel)
# No additional "Starting reseller usage sync" log appears
```

## Verification Checklist

- [ ] Default interval is 5 minutes when no setting exists
- [ ] Interval can be set to 1, 2, 3, 4, or 5 minutes
- [ ] Values < 1 are clamped to 1
- [ ] Values > 5 are clamped to 5
- [ ] Scheduler runs every minute but only dispatches when minute % interval === 0
- [ ] Job implements ShouldBeUnique interface
- [ ] Job has $uniqueFor = 300 (5 minutes)
- [ ] Overlapping runs are prevented
- [ ] Configuration changes take effect immediately (no restart required)
- [ ] Logs show correct interval value
- [ ] All existing tests pass
- [ ] No regressions in reseller functionality

## Rollback Procedure

If issues are found and you need to rollback:

```bash
# Set interval back to 15 minutes (old behavior)
php artisan tinker
Setting::setValue('reseller.usage_sync_interval_minutes', '15');

# Note: The code will clamp this to 5 (maximum allowed in new version)
# To fully rollback to old 15-minute behavior, you would need to revert the code changes
```

## Performance Monitoring

Monitor these metrics after deployment:

1. **Queue processing time**: Ensure jobs complete within timeout (600s)
2. **Database load**: Monitor query counts during sync
3. **API rate limits**: Check panel API usage doesn't hit limits
4. **Log volume**: Verify log rotation handles increased frequency
5. **Cache performance**: ShouldBeUnique uses cache for locks

## Troubleshooting

### Job Not Running
```bash
# Check scheduler is running
php artisan schedule:list

# Check queue workers
ps aux | grep "artisan queue:work"

# Check setting value
php artisan tinker
Setting::get('reseller.usage_sync_interval_minutes')
```

### Job Running Too Frequently
```bash
# Verify interval setting
php artisan tinker
Setting::get('reseller.usage_sync_interval_minutes')

# Check current minute alignment
date +%M
```

### Jobs Overlapping (Should Not Happen)
```bash
# Check if ShouldBeUnique is working
php artisan tinker
$job = new \Modules\Reseller\Jobs\SyncResellerUsageJob();
echo $job->uniqueFor; // Should be 300

# Check cache is working
php artisan cache:clear
php artisan config:cache
```

## Success Criteria

✅ Job runs at configured frequency (1-5 minutes)  
✅ No overlapping executions occur  
✅ Configuration changes apply immediately  
✅ All tests pass  
✅ Logs show expected behavior  
✅ No performance degradation  
✅ No errors in logs  

## Contact

If you encounter issues during QA:
- Review logs in `storage/logs/laravel.log`
- Check the documentation: `docs/USAGE_SYNC_CONFIGURATION.md`
- Refer to tests: `tests/Feature/UsageSyncSchedulerTest.php` and `tests/Feature/JobUniquenessTest.php`
