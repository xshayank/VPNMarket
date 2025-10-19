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
        Schema::create('reseller_config_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reseller_config_id')->constrained()->onDelete('cascade');
            $table->string('type');
            $table->json('meta')->nullable();
            $table->timestamps();
            
            $table->index('reseller_config_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reseller_config_events');
    }
};
