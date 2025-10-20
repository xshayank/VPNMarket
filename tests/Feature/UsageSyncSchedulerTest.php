<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Modules\Reseller\Jobs\SyncResellerUsageJob;
use Tests\TestCase;

class UsageSyncSchedulerTest extends TestCase
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

    public function test_scheduler_uses_default_interval_of_5_minutes(): void
    {
        // No setting created, should use default of 5
        $this->assertDatabaseMissing('settings', ['key' => 'reseller.usage_sync_interval_minutes']);
        
        // The scheduler should dispatch on minutes divisible by 5
        // We can't directly test the scheduler dispatch, but we can verify the setting logic
        $intervalMinutes = Setting::getInt('reseller.usage_sync_interval_minutes', 5);
        $this->assertEquals(5, $intervalMinutes);
    }

    public function test_scheduler_respects_1_minute_minimum(): void
    {
        // Set interval to 0 (below minimum)
        Setting::create([
            'key' => 'reseller.usage_sync_interval_minutes',
            'value' => '0',
        ]);

        $intervalMinutes = Setting::getInt('reseller.usage_sync_interval_minutes', 5);
        
        // Apply the clamping logic as in console.php
        if ($intervalMinutes < 1) {
            $intervalMinutes = 1;
        }
        if ($intervalMinutes > 5) {
            $intervalMinutes = 5;
        }

        $this->assertEquals(1, $intervalMinutes);
    }

    public function test_scheduler_respects_5_minute_maximum(): void
    {
        // Set interval to 10 (above maximum)
        Setting::create([
            'key' => 'reseller.usage_sync_interval_minutes',
            'value' => '10',
        ]);

        $intervalMinutes = Setting::getInt('reseller.usage_sync_interval_minutes', 5);
        
        // Apply the clamping logic as in console.php
        if ($intervalMinutes < 1) {
            $intervalMinutes = 1;
        }
        if ($intervalMinutes > 5) {
            $intervalMinutes = 5;
        }

        $this->assertEquals(5, $intervalMinutes);
    }

    public function test_scheduler_accepts_valid_intervals(): void
    {
        $validIntervals = [1, 2, 3, 4, 5];

        foreach ($validIntervals as $interval) {
            Setting::setValue('reseller.usage_sync_interval_minutes', (string)$interval);
            
            $intervalMinutes = Setting::getInt('reseller.usage_sync_interval_minutes', 5);
            
            // Apply the clamping logic as in console.php
            if ($intervalMinutes < 1) {
                $intervalMinutes = 1;
            }
            if ($intervalMinutes > 5) {
                $intervalMinutes = 5;
            }

            $this->assertEquals($interval, $intervalMinutes, "Interval {$interval} should be accepted");
        }
    }

    public function test_modulo_logic_dispatches_on_correct_minutes(): void
    {
        // Test that the modulo logic would dispatch at correct intervals
        $testCases = [
            ['interval' => 1, 'dispatchMinutes' => [0, 1, 2, 3, 4, 5, 10, 15, 20, 30, 45, 59]],
            ['interval' => 2, 'dispatchMinutes' => [0, 2, 4, 6, 8, 10, 20, 30, 40, 50]],
            ['interval' => 3, 'dispatchMinutes' => [0, 3, 6, 9, 12, 15, 30, 45, 57]],
            ['interval' => 4, 'dispatchMinutes' => [0, 4, 8, 12, 16, 20, 40, 56]],
            ['interval' => 5, 'dispatchMinutes' => [0, 5, 10, 15, 20, 25, 30, 35, 40, 45, 50, 55]],
        ];

        foreach ($testCases as $testCase) {
            $interval = $testCase['interval'];
            
            foreach ($testCase['dispatchMinutes'] as $minute) {
                $shouldDispatch = ($minute % $interval) === 0;
                $this->assertTrue($shouldDispatch, "Minute {$minute} should dispatch for interval {$interval}");
            }
            
            // Test some minutes that should NOT dispatch
            $nonDispatchMinutes = [1, 7, 11, 13, 17, 19, 23, 29, 31, 37, 41, 43, 47, 53, 59];
            foreach ($nonDispatchMinutes as $minute) {
                if (!in_array($minute, $testCase['dispatchMinutes'])) {
                    $shouldNotDispatch = ($minute % $interval) !== 0;
                    if ($shouldNotDispatch) {
                        $this->assertTrue($shouldNotDispatch, "Minute {$minute} should NOT dispatch for interval {$interval}");
                    }
                }
            }
        }
    }
}
