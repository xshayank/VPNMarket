<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class ResellerSettingsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $settings = [
            [
                'key' => 'reseller.username_prefix',
                'value' => 'resell',
            ],
            [
                'key' => 'reseller.bulk_max_quantity',
                'value' => '50',
            ],
            [
                'key' => 'reseller.configs_max_active',
                'value' => '50',
            ],
            [
                'key' => 'reseller.usage_sync_interval_minutes',
                'value' => '5',
            ],
        ];

        foreach ($settings as $setting) {
            Setting::firstOrCreate(
                ['key' => $setting['key']],
                ['value' => $setting['value']]
            );
        }
    }
}
