<?php

namespace Tests\Feature;

use App\Models\Panel;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\ResellerConfigEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ResellerAuditObserverTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_status_update_creates_audit_event(): void
    {
        // Arrange: Create a config
        $panel = Panel::factory()->create([
            'panel_type' => 'marzneshin',
            'is_active' => true,
        ]);

        $reseller = Reseller::factory()->create([
            'type' => 'traffic',
            'status' => 'active',
        ]);

        $config = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'status' => 'active',
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'usage_bytes' => 0,
            'expires_at' => now()->addDays(30),
        ]);

        // Act: Directly update status without creating an event
        $config->update(['status' => 'disabled']);

        // Assert: Audit event should be created
        $this->assertDatabaseHas('reseller_config_events', [
            'reseller_config_id' => $config->id,
            'type' => 'audit_status_changed',
        ]);

        $event = ResellerConfigEvent::where('reseller_config_id', $config->id)
            ->where('type', 'audit_status_changed')
            ->first();

        $this->assertNotNull($event);
        $this->assertEquals('active', $event->meta['from_status']);
        $this->assertEquals('disabled', $event->meta['to_status']);
        $this->assertEquals('system', $event->meta['actor']);
        $this->assertEquals($panel->id, $event->meta['panel_id']);
        $this->assertEquals('marzneshin', $event->meta['panel_type']);
    }

    public function test_observer_does_not_duplicate_event_when_manual_disabled_exists(): void
    {
        // Arrange: Create a config
        $panel = Panel::factory()->create([
            'panel_type' => 'marzneshin',
            'is_active' => true,
        ]);

        $reseller = Reseller::factory()->create([
            'type' => 'traffic',
            'status' => 'active',
        ]);

        $config = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'status' => 'active',
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'usage_bytes' => 0,
            'expires_at' => now()->addDays(30),
        ]);

        // Act: Create a manual_disabled event first (simulating controller/admin action)
        ResellerConfigEvent::create([
            'reseller_config_id' => $config->id,
            'type' => 'manual_disabled',
            'meta' => [
                'reason' => 'admin_action',
                'remote_success' => true,
                'attempts' => 1,
            ],
        ]);

        // Then update the status (which would normally trigger observer)
        $config->update(['status' => 'disabled']);

        // Assert: Should NOT create an audit event because manual_disabled exists recently
        $auditEvents = ResellerConfigEvent::where('reseller_config_id', $config->id)
            ->where('type', 'audit_status_changed')
            ->get();

        $this->assertCount(0, $auditEvents, 'Observer should not create duplicate audit event when manual_disabled exists');

        // Verify only the manual_disabled event exists
        $allEvents = ResellerConfigEvent::where('reseller_config_id', $config->id)->get();
        $this->assertCount(1, $allEvents);
        $this->assertEquals('manual_disabled', $allEvents->first()->type);
    }

    public function test_observer_does_not_duplicate_event_when_auto_disabled_exists(): void
    {
        // Arrange: Create a config
        $panel = Panel::factory()->create([
            'panel_type' => 'marzneshin',
            'is_active' => true,
        ]);

        $reseller = Reseller::factory()->create([
            'type' => 'traffic',
            'status' => 'active',
        ]);

        $config = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'status' => 'active',
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'usage_bytes' => 0,
            'expires_at' => now()->addDays(30),
        ]);

        // Act: Create an auto_disabled event first (simulating job action)
        ResellerConfigEvent::create([
            'reseller_config_id' => $config->id,
            'type' => 'auto_disabled',
            'meta' => [
                'reason' => 'traffic_exceeded',
                'remote_success' => true,
                'attempts' => 1,
            ],
        ]);

        // Then update the status (which would normally trigger observer)
        $config->update(['status' => 'disabled']);

        // Assert: Should NOT create an audit event because auto_disabled exists recently
        $auditEvents = ResellerConfigEvent::where('reseller_config_id', $config->id)
            ->where('type', 'audit_status_changed')
            ->get();

        $this->assertCount(0, $auditEvents, 'Observer should not create duplicate audit event when auto_disabled exists');

        // Verify only the auto_disabled event exists
        $allEvents = ResellerConfigEvent::where('reseller_config_id', $config->id)->get();
        $this->assertCount(1, $allEvents);
        $this->assertEquals('auto_disabled', $allEvents->first()->type);
    }

    public function test_observer_captures_authenticated_user_as_actor(): void
    {
        // Arrange: Create a user and authenticate
        $user = User::factory()->create();
        $this->actingAs($user);

        $panel = Panel::factory()->create([
            'panel_type' => 'marzneshin',
            'is_active' => true,
        ]);

        $reseller = Reseller::factory()->create([
            'type' => 'traffic',
            'status' => 'active',
        ]);

        $config = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'status' => 'active',
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'usage_bytes' => 0,
            'expires_at' => now()->addDays(30),
        ]);

        // Act: Update status while authenticated
        $config->update(['status' => 'disabled']);

        // Assert: Audit event should have user ID as actor
        $event = ResellerConfigEvent::where('reseller_config_id', $config->id)
            ->where('type', 'audit_status_changed')
            ->first();

        $this->assertNotNull($event);
        $this->assertEquals($user->id, $event->meta['actor']);
    }

    public function test_observer_ignores_non_status_updates(): void
    {
        // Arrange: Create a config
        $panel = Panel::factory()->create([
            'panel_type' => 'marzneshin',
            'is_active' => true,
        ]);

        $reseller = Reseller::factory()->create([
            'type' => 'traffic',
            'status' => 'active',
        ]);

        $config = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'status' => 'active',
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'usage_bytes' => 0,
            'expires_at' => now()->addDays(30),
        ]);

        // Act: Update usage_bytes but not status
        $config->update(['usage_bytes' => 1024 * 1024 * 1024]);

        // Assert: No audit event should be created
        $auditEvents = ResellerConfigEvent::where('reseller_config_id', $config->id)
            ->where('type', 'audit_status_changed')
            ->get();

        $this->assertCount(0, $auditEvents, 'Observer should not create audit event for non-status updates');
    }

    public function test_observer_creates_audit_event_after_grace_period(): void
    {
        // Arrange: Create a config
        $panel = Panel::factory()->create([
            'panel_type' => 'marzneshin',
            'is_active' => true,
        ]);

        $reseller = Reseller::factory()->create([
            'type' => 'traffic',
            'status' => 'active',
        ]);

        $config = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'status' => 'active',
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'usage_bytes' => 0,
            'expires_at' => now()->addDays(30),
        ]);

        // First, update status once to create an audit event
        $config->update(['status' => 'disabled']);
        
        // Verify first audit event was created
        $this->assertDatabaseHas('reseller_config_events', [
            'reseller_config_id' => $config->id,
            'type' => 'audit_status_changed',
        ]);

        // Wait for grace period to expire (simulate with timestamp manipulation)
        sleep(3);

        // Act: Update status again (different transition)
        $config->update(['status' => 'expired']);

        // Assert: Should create a second audit event because the previous event is old
        $auditEvents = ResellerConfigEvent::where('reseller_config_id', $config->id)
            ->where('type', 'audit_status_changed')
            ->get();

        $this->assertCount(2, $auditEvents, 'Observer should create a second audit event when previous event is older than 2 seconds');
    }

    public function test_multiple_status_changes_create_multiple_audit_events(): void
    {
        // Arrange: Create a config
        $panel = Panel::factory()->create([
            'panel_type' => 'marzneshin',
            'is_active' => true,
        ]);

        $reseller = Reseller::factory()->create([
            'type' => 'traffic',
            'status' => 'active',
        ]);

        $config = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'status' => 'active',
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'usage_bytes' => 0,
            'expires_at' => now()->addDays(30),
        ]);

        // Act: Make multiple status changes with delays
        $config->update(['status' => 'disabled']);
        sleep(3); // Wait for grace period to expire

        $config->update(['status' => 'active']);
        sleep(3); // Wait for grace period to expire

        $config->update(['status' => 'expired']);

        // Assert: Should have 3 audit events
        $auditEvents = ResellerConfigEvent::where('reseller_config_id', $config->id)
            ->where('type', 'audit_status_changed')
            ->orderBy('created_at')
            ->get();

        $this->assertCount(3, $auditEvents);
        
        $this->assertEquals('active', $auditEvents[0]->meta['from_status']);
        $this->assertEquals('disabled', $auditEvents[0]->meta['to_status']);
        
        $this->assertEquals('disabled', $auditEvents[1]->meta['from_status']);
        $this->assertEquals('active', $auditEvents[1]->meta['to_status']);
        
        $this->assertEquals('active', $auditEvents[2]->meta['from_status']);
        $this->assertEquals('expired', $auditEvents[2]->meta['to_status']);
    }
}
