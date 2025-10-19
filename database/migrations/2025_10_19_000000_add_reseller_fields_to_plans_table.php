<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->boolean('reseller_visible')->default(false);
            $table->decimal('reseller_price', 12, 2)->nullable();
            $table->decimal('reseller_discount_percent', 5, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn([
                'reseller_visible',
                'reseller_price',
                'reseller_discount_percent',
            ]);
        });
    }
};
