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
            $table->string('prefix', 50)->nullable()->after('comment');
            $table->string('custom_name', 100)->nullable()->after('prefix');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reseller_configs', function (Blueprint $table) {
            $table->dropColumn(['prefix', 'custom_name']);
        });
    }
};
