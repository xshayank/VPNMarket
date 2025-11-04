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
            // Add Eylandoo allowed node IDs field (similar to marzneshin_allowed_service_ids)
            $table->json('eylandoo_allowed_node_ids')->nullable()->after('marzneshin_allowed_service_ids');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('resellers', function (Blueprint $table) {
            $table->dropColumn('eylandoo_allowed_node_ids');
        });
    }
};
