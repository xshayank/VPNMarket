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
        Schema::create('reseller_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reseller_id')->constrained()->onDelete('cascade');
            $table->string('external_username');
            $table->bigInteger('traffic_limit_bytes');
            $table->bigInteger('usage_bytes')->default(0);
            $table->timestamp('expires_at');
            $table->enum('status', ['active', 'disabled', 'expired', 'deleted'])->default('active');
            $table->enum('panel_type', ['marzban', 'marzneshin', 'xui', 'ovpanel']);
            $table->string('panel_user_id')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamp('disabled_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
            
            $table->index(['reseller_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reseller_configs');
    }
};
