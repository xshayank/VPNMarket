<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // For SQLite, we don't need to modify the enum since SQLite doesn't enforce enum constraints
        // For MySQL/PostgreSQL, modify the enum to include the new value
        $driver = Schema::connection(null)->getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE resellers MODIFY COLUMN status ENUM('active', 'suspended', 'suspended_wallet') DEFAULT 'active'");
        } elseif ($driver === 'pgsql') {
            // PostgreSQL approach: Add new enum value if it doesn't exist
            DB::statement("ALTER TYPE reseller_status ADD VALUE IF NOT EXISTS 'suspended_wallet'");
        }
        // SQLite: No action needed - it stores enums as strings
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // For SQLite, no action needed
        // For MySQL/PostgreSQL, revert the enum
        $driver = Schema::connection(null)->getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE resellers MODIFY COLUMN status ENUM('active', 'suspended') DEFAULT 'active'");
        }
        // Note: PostgreSQL doesn't support removing enum values easily, so we skip the down migration for it
    }
};
