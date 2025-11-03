<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Panel;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\ResellerConfigEvent;
use App\Models\Setting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Reseller\Jobs\SyncResellerUsageJob;
use Tests\TestCase;

class ResellerTehranTimezoneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Mock Log to avoid output during tests
        Log::shouldReceive('info')->andReturnNull();
        Log::shouldReceive('notice')->andReturnNull();
        Log::shouldReceive('warning')->andReturnNull();
        Log::shouldReceive('error')->andReturnNull();

        // Ensure timezone is set to Asia/Tehran
        config(['app.timezone' => 'Asia/Tehran']);
        date_default_timezone_set('Asia/Tehran');
    }

    public function test_config_expires_at_midnight_tehran_time(): void
    {
        // Create a config that expires on 2025-11-03
        $expiresAt = Carbon::parse('2025-11-03 00:00:00', 'Asia/Tehran');
        
        $config = ResellerConfig::factory()->create([
            'expires_at' => $expiresAt,
            'status' => 'active',
        ]);

        // Test at 2025-11-02 23:59:59 (not expired yet)
        Carbon::setTestNow(Carbon::parse('2025-11-02 23:59:59', 'Asia/Tehran'));
        $this->assertFalse($config->isExpiredByTime());

        // Test at 2025-11-03 00:00:00 (expired exactly at midnight)
        Carbon::setTestNow(Carbon::parse('2025-11-03 00:00:00', 'Asia/Tehran'));
        $this->assertTrue($config->isExpiredByTime());

        // Test at 2025-11-03 00:00:01 (expired)
        Carbon::setTestNow(Carbon::parse('2025-11-03 00:00:01', 'Asia/Tehran'));
        $this->assertTrue($config->isExpiredByTime());

        Carbon::setTestNow(); // Reset
    }

    public function test_config_expires_at_midnight_even_with_time_component(): void
    {
        // Create a config with expires_at having a time component (e.g., 14:30)
        // Should still expire at midnight of that day
        $expiresAt = Carbon::parse('2025-11-03 14:30:00', 'Asia/Tehran');
        
        $config = ResellerConfig::factory()->create([
            'expires_at' => $expiresAt,
            'status' => 'active',
        ]);

        // Test at 2025-11-02 23:59:59 (not expired yet)
        Carbon::setTestNow(Carbon::parse('2025-11-02 23:59:59', 'Asia/Tehran'));
        $this->assertFalse($config->isExpiredByTime());

        // Test at 2025-11-03 00:00:00 (expired at midnight, not 14:30)
        Carbon::setTestNow(Carbon::parse('2025-11-03 00:00:00', 'Asia/Tehran'));
        $this->assertTrue($config->isExpiredByTime());

        Carbon::setTestNow(); // Reset
    }

    public function test_reseller_window_becomes_invalid_at_midnight_tehran(): void
    {
        $user = User::factory()->create();
        $panel = Panel::factory()->create(['panel_type' => 'marzban']);

        // Create a reseller with window ending on 2025-11-03
        $windowEndsAt = Carbon::parse('2025-11-03 00:00:00', 'Asia/Tehran');
        
        $reseller = Reseller::factory()->create([
            'user_id' => $user->id,
            'type' => 'traffic',
            'status' => 'active',
            'panel_id' => $panel->id,
            'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
            'traffic_used_bytes' => 10 * 1024 * 1024 * 1024,
            'window_starts_at' => Carbon::parse('2025-10-01 00:00:00', 'Asia/Tehran'),
            'window_ends_at' => $windowEndsAt,
        ]);

        // Test at 2025-11-02 23:59:59 (window still valid)
        Carbon::setTestNow(Carbon::parse('2025-11-02 23:59:59', 'Asia/Tehran'));
        $this->assertTrue($reseller->isWindowValid());

        // Test at 2025-11-03 00:00:00 (window becomes invalid exactly at midnight)
        Carbon::setTestNow(Carbon::parse('2025-11-03 00:00:00', 'Asia/Tehran'));
        $this->assertFalse($reseller->isWindowValid());

        // Test at 2025-11-03 00:00:01 (window invalid)
        Carbon::setTestNow(Carbon::parse('2025-11-03 00:00:01', 'Asia/Tehran'));
        $this->assertFalse($reseller->isWindowValid());

        Carbon::setTestNow(); // Reset
    }

    public function test_reseller_window_becomes_invalid_at_midnight_even_with_time_component(): void
    {
        $user = User::factory()->create();
        $panel = Panel::factory()->create(['panel_type' => 'marzban']);

        // Create a reseller with window ending on 2025-11-03 14:30 (has time component)
        // Should become invalid at midnight, not at 14:30
        $windowEndsAt = Carbon::parse('2025-11-03 14:30:00', 'Asia/Tehran');
        
        $reseller = Reseller::factory()->create([
            'user_id' => $user->id,
            'type' => 'traffic',
            'status' => 'active',
            'panel_id' => $panel->id,
            'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
            'traffic_used_bytes' => 10 * 1024 * 1024 * 1024,
            'window_starts_at' => Carbon::parse('2025-10-01 00:00:00', 'Asia/Tehran'),
            'window_ends_at' => $windowEndsAt,
        ]);

        // Test at 2025-11-02 23:59:59 (window still valid)
        Carbon::setTestNow(Carbon::parse('2025-11-02 23:59:59', 'Asia/Tehran'));
        $this->assertTrue($reseller->isWindowValid());

        // Test at 2025-11-03 00:00:00 (window becomes invalid at midnight, not 14:30)
        Carbon::setTestNow(Carbon::parse('2025-11-03 00:00:00', 'Asia/Tehran'));
        $this->assertFalse($reseller->isWindowValid());

        Carbon::setTestNow(); // Reset
    }

    public function test_sync_job_suspends_reseller_at_midnight_when_window_expires(): void
    {
        $panel = Panel::factory()->create([
            'panel_type' => 'marzneshin',
            'url' => 'https://example.com',
            'username' => 'admin',
            'password' => 'password',
            'is_active' => true,
            'extra' => ['node_hostname' => 'https://node.example.com'],
        ]);

        // Create reseller with window ending on 2025-11-03
        $windowEndsAt = Carbon::parse('2025-11-03 00:00:00', 'Asia/Tehran');
        
        $reseller = Reseller::factory()->create([
            'type' => 'traffic',
            'status' => 'active',
            'panel_id' => $panel->id,
            'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
            'traffic_used_bytes' => 10 * 1024 * 1024 * 1024,
            'window_starts_at' => Carbon::parse('2025-10-01 00:00:00', 'Asia/Tehran'),
            'window_ends_at' => $windowEndsAt,
        ]);

        // Create active configs
        $config = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'testuser',
            'status' => 'active',
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'usage_bytes' => 1 * 1024 * 1024 * 1024,
            'expires_at' => Carbon::parse('2025-12-01 00:00:00', 'Asia/Tehran'),
        ]);

        // Mock HTTP responses
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/users/testuser' => Http::response([
                'username' => 'testuser',
                'used_traffic' => 1 * 1024 * 1024 * 1024,
            ], 200),
            '*/api/users/*/disable' => Http::response([], 200),
        ]);

        // Set time to 2025-11-03 00:00:00 (exactly when window expires)
        Carbon::setTestNow(Carbon::parse('2025-11-03 00:00:00', 'Asia/Tehran'));

        // Run the sync job
        $job = new SyncResellerUsageJob();
        $job->handle();

        // Reseller should be suspended
        $reseller->refresh();
        $this->assertEquals('suspended', $reseller->status);

        // Config should be disabled
        $config->refresh();
        $this->assertEquals('disabled', $config->status);

        // Check audit log for reseller suspension
        $this->assertTrue(
            AuditLog::where('action', 'reseller_suspended')
                ->where('target_id', $reseller->id)
                ->where('target_type', 'reseller')
                ->where('reason', 'reseller_window_expired')
                ->exists()
        );

        // Check audit log for config auto-disable
        $this->assertTrue(
            AuditLog::where('action', 'config_auto_disabled')
                ->where('target_id', $config->id)
                ->where('target_type', 'config')
                ->where('reason', 'reseller_window_expired')
                ->exists()
        );

        // Check config event
        $event = $config->events()->where('type', 'auto_disabled')->first();
        $this->assertNotNull($event);
        $this->assertEquals('reseller_window_expired', $event->meta['reason']);

        Carbon::setTestNow(); // Reset
    }

    public function test_no_time_expiry_grace_applied(): void
    {
        $panel = Panel::factory()->create([
            'panel_type' => 'marzneshin',
            'url' => 'https://example.com',
            'username' => 'admin',
            'password' => 'password',
            'is_active' => true,
            'extra' => ['node_hostname' => 'https://node.example.com'],
        ]);

        // Create reseller
        $reseller = Reseller::factory()->create([
            'type' => 'traffic',
            'status' => 'active',
            'panel_id' => $panel->id,
            'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
            'traffic_used_bytes' => 10 * 1024 * 1024 * 1024,
            'window_starts_at' => Carbon::parse('2025-10-01 00:00:00', 'Asia/Tehran'),
            'window_ends_at' => Carbon::parse('2025-12-01 00:00:00', 'Asia/Tehran'),
        ]);

        // Create config that expires on 2025-11-03
        $config = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'testuser',
            'status' => 'active',
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'usage_bytes' => 1 * 1024 * 1024 * 1024,
            'expires_at' => Carbon::parse('2025-11-03 00:00:00', 'Asia/Tehran'),
        ]);

        // Mock HTTP responses
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/users/testuser' => Http::response([
                'username' => 'testuser',
                'used_traffic' => 1 * 1024 * 1024 * 1024,
            ], 200),
            '*/api/users/*/disable' => Http::response([], 200),
        ]);

        // Disable per-config enforcement to test only time expiry
        Setting::create([
            'key' => 'reseller.allow_config_overrun',
            'value' => 'false',
        ]);

        // Set time to 2025-11-03 00:00:00 (exactly when config expires)
        // No grace should be applied
        Carbon::setTestNow(Carbon::parse('2025-11-03 00:00:00', 'Asia/Tehran'));

        // Run the sync job
        $job = new SyncResellerUsageJob();
        $job->handle();

        // Config should be disabled immediately at midnight, no grace
        $config->refresh();
        $this->assertEquals('expired', $config->status);

        // Check config event
        $event = $config->events()->where('type', 'auto_disabled')->first();
        $this->assertNotNull($event);
        $this->assertEquals('time_expired', $event->meta['reason']);

        Carbon::setTestNow(); // Reset
    }

    public function test_reseller_created_with_window_days_uses_startofday(): void
    {
        $user = User::factory()->create();
        $panel = Panel::factory()->create(['panel_type' => 'marzban']);

        $windowDays = 30;

        // Set test time to a specific time (e.g., 14:30)
        Carbon::setTestNow(Carbon::parse('2025-11-01 14:30:00', 'Asia/Tehran'));

        // Simulate CreateReseller mutation
        $formData = [
            'user_id' => $user->id,
            'type' => 'traffic',
            'status' => 'active',
            'panel_id' => $panel->id,
            'traffic_total_gb' => 100,
            'window_days' => $windowDays,
        ];

        // Apply mutation logic
        $mutatedData = $formData;
        if ($mutatedData['type'] === 'traffic' && isset($mutatedData['traffic_total_gb'])) {
            $mutatedData['traffic_total_bytes'] = (int) ($mutatedData['traffic_total_gb'] * 1024 * 1024 * 1024);
            unset($mutatedData['traffic_total_gb']);
        }
        if ($mutatedData['type'] === 'traffic' && isset($mutatedData['window_days']) && $mutatedData['window_days'] > 0) {
            $windowDaysValue = (int) $mutatedData['window_days'];
            $mutatedData['window_starts_at'] = now()->startOfDay();
            $mutatedData['window_ends_at'] = now()->addDays($windowDaysValue)->startOfDay();
            unset($mutatedData['window_days']);
        }

        $reseller = Reseller::create($mutatedData);

        // Verify window_starts_at is at start of day
        $this->assertEquals('2025-11-01 00:00:00', $reseller->window_starts_at->format('Y-m-d H:i:s'));

        // Verify window_ends_at is at start of day (not 14:30)
        $this->assertEquals('2025-12-01 00:00:00', $reseller->window_ends_at->format('Y-m-d H:i:s'));

        Carbon::setTestNow(); // Reset
    }

    public function test_config_created_with_expires_days_uses_startofday(): void
    {
        // Set test time to a specific time (e.g., 14:30)
        Carbon::setTestNow(Carbon::parse('2025-11-01 14:30:00', 'Asia/Tehran'));

        $expiresDays = 30;
        
        // Simulate ConfigController logic
        $expiresAt = now()->addDays($expiresDays)->startOfDay();

        // Verify expires_at is at start of day (not 14:30)
        $this->assertEquals('2025-12-01 00:00:00', $expiresAt->format('Y-m-d H:i:s'));

        Carbon::setTestNow(); // Reset
    }
}
