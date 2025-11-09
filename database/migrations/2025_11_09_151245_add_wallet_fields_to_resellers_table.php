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
            // Add billing type: 'traffic' (default, existing behavior) or 'wallet' (new hourly billing)
            $table->string('billing_type', 20)->default('traffic')->after('type');
            
            // Wallet balance in تومان (integer to avoid floating point issues)
            $table->bigInteger('wallet_balance')->default(0)->after('billing_type');
            
            // Optional per-reseller price override (in تومان per GB)
            // If null, use default from config('billing.wallet.price_per_gb', 780)
            $table->integer('wallet_price_per_gb')->nullable()->after('wallet_balance');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('resellers', function (Blueprint $table) {
            $table->dropColumn(['billing_type', 'wallet_balance', 'wallet_price_per_gb']);
        });
    }
};
