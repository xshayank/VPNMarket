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
        Schema::table('reseller_orders', function (Blueprint $table) {
            $table->integer('quantity')->default(1)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reseller_orders', function (Blueprint $table) {
            // Remove default value (revert to no default)
            $table->integer('quantity')->default(null)->change();
        });
    }
};
