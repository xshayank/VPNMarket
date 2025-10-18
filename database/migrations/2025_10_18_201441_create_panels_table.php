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
        if (! Schema::hasTable('panels')) {
            Schema::create('panels', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('url');
                $table->enum('panel_type', ['marzban', 'marzneshin', 'xui', 'v2ray', 'other'])->default('marzban');
                $table->string('username')->nullable();
                $table->text('password')->nullable(); // Encrypted
                $table->text('api_token')->nullable(); // Encrypted
                $table->json('extra')->nullable(); // Additional panel-specific configuration
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        // If table exists but is missing columns, add them
        if (Schema::hasTable('panels')) {
            Schema::table('panels', function (Blueprint $table) {
                if (! Schema::hasColumn('panels', 'panel_type')) {
                    $table->enum('panel_type', ['marzban', 'marzneshin', 'xui', 'v2ray', 'other'])->default('marzban')->after('url');
                }
                if (! Schema::hasColumn('panels', 'api_token')) {
                    $table->text('api_token')->nullable()->after('password');
                }
                if (! Schema::hasColumn('panels', 'extra')) {
                    $table->json('extra')->nullable()->after('api_token');
                }
                if (! Schema::hasColumn('panels', 'is_active')) {
                    $table->boolean('is_active')->default(true)->after('extra');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('panels');
    }
};
