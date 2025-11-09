<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * This migration is idempotent - it checks the current column type before altering.
     */
    public function up(): void
    {
        // Check if the column is an ENUM and whether 'wallet' is already included
        $tableName = 'resellers';
        $columnName = 'type';
        $database = config('database.connections.mysql.database');

        // Query information_schema to get current column definition
        $columnInfo = DB::selectOne(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS 
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            [$database, $tableName, $columnName]
        );

        if ($columnInfo) {
            $columnType = $columnInfo->COLUMN_TYPE;
            
            // Check if it's an ENUM type
            if (str_starts_with($columnType, 'enum')) {
                // Check if 'wallet' is already in the ENUM
                if (!str_contains($columnType, "'wallet'")) {
                    // Alter the ENUM to include 'wallet'
                    DB::statement("ALTER TABLE `{$tableName}` 
                        MODIFY COLUMN `{$columnName}` 
                        ENUM('plan', 'traffic', 'wallet') NOT NULL DEFAULT 'traffic'");
                }
            } elseif (str_contains($columnType, 'varchar') || str_contains($columnType, 'char')) {
                // If it's a VARCHAR/CHAR, no action needed - it can already store 'wallet'
                // Just ensure there's no CHECK constraint blocking it (MySQL 8.0.16+)
                // Note: Laravel doesn't create CHECK constraints by default for string columns
            }
        }
    }

    /**
     * Reverse the migrations.
     * 
     * Note: Removing 'wallet' from the ENUM would fail if any rows have type='wallet'.
     * This down migration will only succeed if no wallet-type resellers exist.
     */
    public function down(): void
    {
        $tableName = 'resellers';
        $columnName = 'type';

        // Check if any resellers have type='wallet'
        $walletCount = DB::table($tableName)->where($columnName, 'wallet')->count();

        if ($walletCount > 0) {
            throw new \RuntimeException(
                "Cannot rollback: {$walletCount} reseller(s) with type='wallet' exist. " .
                "Remove or update them before rolling back this migration."
            );
        }

        // Revert ENUM to original values
        DB::statement("ALTER TABLE `{$tableName}` 
            MODIFY COLUMN `{$columnName}` 
            ENUM('plan', 'traffic') NOT NULL DEFAULT 'plan'");
    }
};
