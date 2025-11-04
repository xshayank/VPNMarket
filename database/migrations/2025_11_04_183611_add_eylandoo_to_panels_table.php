<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Update panel_type enum to include 'eylandoo'
        DB::statement("ALTER TABLE panels MODIFY COLUMN panel_type ENUM('marzban', 'marzneshin', 'xui', 'v2ray', 'eylandoo', 'other') NOT NULL DEFAULT 'marzban'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert panel_type enum to original values
        // Note: This will fail if any panels have panel_type = 'eylandoo'
        DB::statement("ALTER TABLE panels MODIFY COLUMN panel_type ENUM('marzban', 'marzneshin', 'xui', 'v2ray', 'other') NOT NULL DEFAULT 'marzban'");
    }
};
