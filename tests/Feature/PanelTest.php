<?php

namespace Tests\Feature;

use App\Models\Panel;
use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_panel(): void
    {
        $panel = Panel::create([
            'name' => 'Test Panel',
            'url' => 'https://panel.test.com',
            'type' => 'marzban',
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('panels', [
            'name' => 'Test Panel',
            'url' => 'https://panel.test.com',
            'type' => 'marzban',
        ]);
    }

    public function test_panel_has_plans_relationship(): void
    {
        $panel = Panel::create([
            'name' => 'Test Panel',
            'url' => 'https://panel.test.com',
            'type' => 'marzban',
            'is_active' => true,
        ]);

        $plan = Plan::create([
            'name' => 'Test Plan',
            'price' => 100,
            'currency' => 'تومان',
            'features' => 'Feature 1',
            'volume_gb' => 30,
            'duration_days' => 30,
            'panel_id' => $panel->id,
            'is_active' => true,
        ]);

        $this->assertEquals(1, $panel->plans()->count());
        $this->assertEquals($panel->id, $plan->panel->id);
    }
}
