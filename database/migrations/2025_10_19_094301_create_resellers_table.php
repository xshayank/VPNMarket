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
        Schema::create('resellers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->onDelete('cascade');
            $table->enum('type', ['plan', 'traffic'])->default('plan');
            $table->enum('status', ['active', 'suspended'])->default('active');
            $table->string('username_prefix')->nullable();
            $table->bigInteger('traffic_total_bytes')->nullable();
            $table->bigInteger('traffic_used_bytes')->default(0);
            $table->timestamp('window_starts_at')->nullable();
            $table->timestamp('window_ends_at')->nullable();
            $table->json('marzneshin_allowed_service_ids')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('resellers');
    }
};
