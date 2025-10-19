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
        Schema::create('reseller_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reseller_id')->constrained()->onDelete('cascade');
            $table->foreignId('plan_id')->constrained()->onDelete('cascade');
            $table->integer('quantity');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('total_price', 12, 2);
            $table->enum('price_source', ['override_price', 'override_percent', 'plan_price', 'plan_percent']);
            $table->enum('delivery_mode', ['download', 'onscreen']);
            $table->enum('status', ['pending', 'paid', 'provisioning', 'fulfilled', 'failed'])->default('pending');
            $table->timestamp('fulfilled_at')->nullable();
            $table->json('artifacts')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reseller_orders');
    }
};
