<?php

namespace Tests\Feature;

use App\Models\Panel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OVPanelAdminIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_ovpanel_via_model(): void
    {
        $panel = Panel::create([
            'name' => 'Test OV-Panel',
            'url' => 'https://ovpanel.test.com',
            'panel_type' => 'ovpanel',
            'username' => 'admin',
            'password' => 'secret123',
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('panels', [
            'name' => 'Test OV-Panel',
            'url' => 'https://ovpanel.test.com',
            'panel_type' => 'ovpanel',
        ]);

        $this->assertEquals('ovpanel', $panel->panel_type);
    }

    public function test_admin_can_create_ovpanel_via_api(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)->postJson('/api/admin/panels', [
            'name' => 'New OV-Panel',
            'url' => 'https://new-ovpanel.test.com',
            'panel_type' => 'ovpanel',
            'username' => 'admin',
            'password' => 'secret',
            'is_active' => true,
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'id',
                    'name',
                    'url',
                    'panel_type',
                    'has_password',
                ]
            ])
            ->assertJsonPath('data.panel_type', 'ovpanel');

        $this->assertDatabaseHas('panels', [
            'name' => 'New OV-Panel',
            'url' => 'https://new-ovpanel.test.com',
            'panel_type' => 'ovpanel',
        ]);
    }

    public function test_admin_can_update_panel_to_ovpanel_via_api(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        
        $panel = Panel::create([
            'name' => 'Regular Panel',
            'url' => 'https://regular.test.com',
            'panel_type' => 'marzban',
        ]);

        $response = $this->actingAs($admin)->putJson("/api/admin/panels/{$panel->id}", [
            'panel_type' => 'ovpanel',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.panel_type', 'ovpanel');

        $this->assertDatabaseHas('panels', [
            'id' => $panel->id,
            'panel_type' => 'ovpanel',
        ]);
    }

    public function test_invalid_panel_type_rejected(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)->postJson('/api/admin/panels', [
            'name' => 'Invalid Panel',
            'url' => 'https://invalid.test.com',
            'panel_type' => 'invalid_type',
            'username' => 'admin',
            'password' => 'secret',
            'is_active' => true,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['panel_type']);
    }

    public function test_ovpanel_type_in_valid_panel_types(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        // Test all valid panel types
        $validTypes = ['marzban', 'marzneshin', 'xui', 'ovpanel', 'v2ray', 'other'];

        foreach ($validTypes as $type) {
            $response = $this->actingAs($admin)->postJson('/api/admin/panels', [
                'name' => "Test {$type} Panel",
                'url' => "https://{$type}.test.com",
                'panel_type' => $type,
                'is_active' => true,
            ]);

            $response->assertStatus(201)
                ->assertJsonPath('data.panel_type', $type);
        }

        // Verify all were created
        foreach ($validTypes as $type) {
            $this->assertDatabaseHas('panels', [
                'panel_type' => $type,
            ]);
        }
    }
}
