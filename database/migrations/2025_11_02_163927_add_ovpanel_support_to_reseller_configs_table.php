<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('reseller_configs', function (Blueprint $table) {
            // Add columns for .ovpn file management
            $table->string('ovpn_path')->nullable()->after('subscription_url');
            $table->string('ovpn_token', 64)->nullable()->after('ovpn_path');
            $table->timestamp('ovpn_token_expires_at')->nullable()->after('ovpn_token');
            
            // Add index for token lookup
            $table->index('ovpn_token');
        });
        
        // Add 'ovpanel' to panel_type enum (MySQL specific)
        // SQLite doesn't support enum modification, so we check the driver
        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE reseller_configs MODIFY panel_type ENUM('marzban', 'marzneshin', 'xui', 'ovpanel')");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reseller_configs', function (Blueprint $table) {
            $table->dropIndex(['ovpn_token']);
            $table->dropColumn(['ovpn_path', 'ovpn_token', 'ovpn_token_expires_at']);
        });
        
        // Revert panel_type enum to original values (MySQL specific)
        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE reseller_configs MODIFY panel_type ENUM('marzban', 'marzneshin', 'xui')");
        }
    }
};
