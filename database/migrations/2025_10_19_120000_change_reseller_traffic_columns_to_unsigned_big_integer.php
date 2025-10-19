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
        Schema::table('resellers', function (Blueprint $table) {
            // Change traffic_total_bytes and traffic_used_bytes to unsignedBigInteger
            // This provides more headroom and correct semantics (bytes cannot be negative)
            $table->unsignedBigInteger('traffic_total_bytes')->nullable()->change();
            $table->unsignedBigInteger('traffic_used_bytes')->default(0)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('resellers', function (Blueprint $table) {
            // Revert to bigInteger
            $table->bigInteger('traffic_total_bytes')->nullable()->change();
            $table->bigInteger('traffic_used_bytes')->default(0)->change();
        });
    }
};
