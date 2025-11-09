<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('resellers', function (Blueprint $table) {
            // Modify status column to allow suspended_wallet value
            // Since we can't directly modify enum, we'll use DB::statement for MySQL
            DB::statement("ALTER TABLE resellers MODIFY COLUMN status ENUM('active', 'suspended', 'suspended_wallet') DEFAULT 'active'");
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('resellers', function (Blueprint $table) {
            // Revert back to original enum values
            DB::statement("ALTER TABLE resellers MODIFY COLUMN status ENUM('active', 'suspended') DEFAULT 'active'");
        });
    }
};
