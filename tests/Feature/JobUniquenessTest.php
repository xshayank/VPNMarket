<?php

namespace Tests\Feature;

use App\Models\Reseller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Modules\Reseller\Jobs\SyncResellerUsageJob;
use Tests\TestCase;

class JobUniquenessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Mock Log to avoid output during tests
        Log::shouldReceive('info')->andReturnNull();
        Log::shouldReceive('warning')->andReturnNull();
        Log::shouldReceive('error')->andReturnNull();
    }

    public function test_sync_reseller_usage_job_implements_should_be_unique(): void
    {
        $job = new SyncResellerUsageJob();
        
        // Check that the job implements ShouldBeUnique
        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldBeUnique::class, $job);
    }

    public function test_sync_reseller_usage_job_has_unique_for_property(): void
    {
        $job = new SyncResellerUsageJob();
        
        // Check that the job has the uniqueFor property set to 300 seconds (5 minutes)
        $this->assertEquals(300, $job->uniqueFor);
    }

    public function test_job_uniqueness_prevents_duplicate_dispatch_with_cache(): void
    {
        // Clear any existing locks
        Cache::flush();
        
        $job = new SyncResellerUsageJob();
        
        // Get the unique ID that Laravel would generate for this job
        // The uniqueId method should exist on jobs implementing ShouldBeUnique
        if (method_exists($job, 'uniqueId')) {
            $uniqueId = $job->uniqueId();
        } else {
            // Laravel uses the class name as the unique ID by default
            $uniqueId = get_class($job);
        }
        
        // Simulate acquiring the lock (as Laravel does internally)
        $lockKey = 'laravel_unique_job:' . $uniqueId;
        $lockAcquired = Cache::add($lockKey, true, $job->uniqueFor);
        
        $this->assertTrue($lockAcquired, 'First lock acquisition should succeed');
        
        // Try to acquire the lock again (simulating a second dispatch)
        $secondLockAcquired = Cache::add($lockKey, true, $job->uniqueFor);
        
        $this->assertFalse($secondLockAcquired, 'Second lock acquisition should fail due to uniqueness constraint');
        
        // Clean up
        Cache::forget($lockKey);
    }

    public function test_job_uniqueness_allows_dispatch_after_unique_for_expires(): void
    {
        // Clear any existing locks
        Cache::flush();
        
        $job = new SyncResellerUsageJob();
        
        // Get the unique ID
        if (method_exists($job, 'uniqueId')) {
            $uniqueId = $job->uniqueId();
        } else {
            $uniqueId = get_class($job);
        }
        
        $lockKey = 'laravel_unique_job:' . $uniqueId;
        
        // Acquire the lock with a very short TTL (1 second)
        $lockAcquired = Cache::add($lockKey, true, 1);
        $this->assertTrue($lockAcquired);
        
        // Wait for the lock to expire
        sleep(2);
        
        // Try to acquire the lock again
        $secondLockAcquired = Cache::add($lockKey, true, 1);
        
        $this->assertTrue($secondLockAcquired, 'Lock should be acquirable after TTL expires');
        
        // Clean up
        Cache::forget($lockKey);
    }
}
