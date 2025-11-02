<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResellerEnforcementSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_page_exists_and_has_correct_values(): void
    {
        // Set some test settings
        Setting::setValue('reseller.allow_config_overrun', 'true');
        Setting::setValue('reseller.auto_disable_grace_percent', '2.5');
        Setting::setValue('reseller.auto_disable_grace_bytes', '104857600'); // 100 MB
        Setting::setValue('reseller.time_expiry_grace_minutes', '30');
        Setting::setValue('reseller.usage_sync_interval_minutes', '5');

        // Retrieve and verify
        $this->assertEquals('true', Setting::get('reseller.allow_config_overrun'));
        $this->assertEquals('2.5', Setting::get('reseller.auto_disable_grace_percent'));
        $this->assertEquals('104857600', Setting::get('reseller.auto_disable_grace_bytes'));
        $this->assertEquals('30', Setting::get('reseller.time_expiry_grace_minutes'));
        $this->assertEquals('5', Setting::get('reseller.usage_sync_interval_minutes'));
    }

    public function test_settings_can_be_updated(): void
    {
        // Initial settings
        Setting::setValue('reseller.auto_disable_grace_percent', '2.0');
        $this->assertEquals('2.0', Setting::get('reseller.auto_disable_grace_percent'));

        // Update setting
        Setting::setValue('reseller.auto_disable_grace_percent', '3.5');
        $this->assertEquals('3.5', Setting::get('reseller.auto_disable_grace_percent'));
    }

    public function test_boolean_settings_work_correctly(): void
    {
        // Test getBool method
        Setting::setValue('reseller.allow_config_overrun', 'true');
        $this->assertTrue(Setting::getBool('reseller.allow_config_overrun'));

        Setting::setValue('reseller.allow_config_overrun', 'false');
        $this->assertFalse(Setting::getBool('reseller.allow_config_overrun'));

        Setting::setValue('reseller.allow_config_overrun', '1');
        $this->assertTrue(Setting::getBool('reseller.allow_config_overrun'));

        Setting::setValue('reseller.allow_config_overrun', '0');
        $this->assertFalse(Setting::getBool('reseller.allow_config_overrun'));
    }

    public function test_default_values_are_returned_when_setting_not_found(): void
    {
        // Clear all settings
        Setting::where('key', 'like', 'reseller.%')->delete();

        // Test defaults
        $this->assertTrue(Setting::getBool('reseller.allow_config_overrun', true));
        $this->assertEquals('2.0', Setting::get('reseller.auto_disable_grace_percent', '2.0'));
        $this->assertEquals('52428800', Setting::get('reseller.auto_disable_grace_bytes', '52428800'));
        $this->assertEquals('0', Setting::get('reseller.time_expiry_grace_minutes', '0'));
        $this->assertEquals('3', Setting::get('reseller.usage_sync_interval_minutes', '3'));
    }

    public function test_health_command_runs_successfully(): void
    {
        $this->artisan('reseller:enforcement:health')
            ->assertExitCode(0)
            ->expectsOutput('=== Reseller Enforcement System Health Check ===');
    }
}
