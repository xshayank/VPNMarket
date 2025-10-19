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
        Schema::table('plans', function (Blueprint $table) {
            $table->boolean('reseller_visible')->default(false)->after('is_active');
            $table->decimal('reseller_price', 12, 2)->nullable()->after('reseller_visible');
            $table->decimal('reseller_discount_percent', 5, 2)->nullable()->after('reseller_price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['reseller_visible', 'reseller_price', 'reseller_discount_percent']);
        });
    }
};
