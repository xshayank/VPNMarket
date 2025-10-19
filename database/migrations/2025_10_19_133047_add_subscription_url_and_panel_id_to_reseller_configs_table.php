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
        Schema::table('reseller_configs', function (Blueprint $table) {
            $table->string('subscription_url')->nullable()->after('panel_user_id');
            $table->foreignId('panel_id')->nullable()->after('subscription_url')->constrained()->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reseller_configs', function (Blueprint $table) {
            $table->dropForeign(['panel_id']);
            $table->dropColumn(['subscription_url', 'panel_id']);
        });
    }
};
