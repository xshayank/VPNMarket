<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resellers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->enum('type', ['plan', 'traffic']);
            $table->enum('status', ['active', 'suspended'])->default('active');
            $table->string('username_prefix')->nullable();
            $table->unsignedBigInteger('traffic_total_bytes')->nullable();
            $table->unsignedBigInteger('traffic_used_bytes')->default(0);
            $table->timestamp('window_starts_at')->nullable();
            $table->timestamp('window_ends_at')->nullable();
            $table->json('marzneshin_allowed_service_ids')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('reseller_allowed_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reseller_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->enum('override_type', ['price', 'percent'])->nullable();
            $table->decimal('override_value', 12, 2)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['reseller_id', 'plan_id']);
        });

        Schema::create('reseller_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reseller_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('total_price', 12, 2);
            $table->enum('price_source', ['override_price', 'override_percent', 'plan_price', 'plan_percent']);
            $table->enum('delivery_mode', ['download', 'onscreen']);
            $table->enum('status', ['pending', 'paid', 'provisioning', 'fulfilled', 'failed'])->default('pending');
            $table->timestamp('fulfilled_at')->nullable();
            $table->json('artifacts')->nullable();
            $table->timestamps();
        });

        Schema::create('reseller_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reseller_id')->constrained()->cascadeOnDelete();
            $table->string('external_username');
            $table->unsignedBigInteger('traffic_limit_bytes');
            $table->unsignedBigInteger('usage_bytes')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->enum('status', ['active', 'disabled', 'expired', 'deleted'])->default('active');
            $table->enum('panel_type', ['marzban', 'marzneshin', 'xui']);
            $table->string('panel_user_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('reseller_config_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reseller_config_id')->constrained('reseller_configs')->cascadeOnDelete();
            $table->string('type');
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_config_events');
        Schema::dropIfExists('reseller_configs');
        Schema::dropIfExists('reseller_orders');
        Schema::dropIfExists('reseller_allowed_plans');
        Schema::dropIfExists('resellers');
    }
};
