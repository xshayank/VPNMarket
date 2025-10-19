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
        Schema::create('reseller_allowed_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reseller_id')->constrained()->onDelete('cascade');
            $table->foreignId('plan_id')->constrained()->onDelete('cascade');
            $table->enum('override_type', ['price', 'percent'])->nullable();
            $table->decimal('override_value', 12, 2)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            
            $table->unique(['reseller_id', 'plan_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reseller_allowed_plans');
    }
};
