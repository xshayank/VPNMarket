<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('reseller_configs', function (Blueprint $table) {
            // Add connections field for Eylandoo (max simultaneous connections)
            $table->integer('connections')->nullable()->after('traffic_limit_bytes');
            
            // Update panel_type enum to include 'eylandoo'
            DB::statement("ALTER TABLE reseller_configs MODIFY COLUMN panel_type ENUM('marzban', 'marzneshin', 'xui', 'eylandoo') NOT NULL");
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reseller_configs', function (Blueprint $table) {
            // Remove connections field
            $table->dropColumn('connections');
            
            // Revert panel_type enum to original values
            DB::statement("ALTER TABLE reseller_configs MODIFY COLUMN panel_type ENUM('marzban', 'marzneshin', 'xui') NOT NULL");
        });
    }
};
