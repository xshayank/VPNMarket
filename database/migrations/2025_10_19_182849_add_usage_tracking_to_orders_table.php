<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('traffic_limit_bytes')->nullable()->after('expires_at');
            $table->unsignedBigInteger('usage_bytes')->default(0)->after('traffic_limit_bytes');
            $table->string('panel_user_id')->nullable()->after('usage_bytes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['traffic_limit_bytes', 'usage_bytes', 'panel_user_id']);
        });
    }
};
