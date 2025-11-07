<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

class EylandooNodeSelectionPermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * This seeder creates the 'configs.select_panel_nodes' permission.
     * This permission is INFORMATIONAL/ADDITIVE ONLY - it does NOT block visibility.
     * The Eylandoo nodes selector is always visible for Eylandoo panel types,
     * regardless of this permission.
     *
     * Purpose:
     * - Audit trail: Track which users have node selection capability
     * - Documentation: Indicate feature availability
     * - Future use: May be used for advanced features
     *
     * To run this seeder:
     * php artisan db:seed --class=EylandooNodeSelectionPermissionSeeder
     *
     * If Filament Shield is installed, refresh cache after seeding:
     * php artisan shield:cache
     */
    public function run(): void
    {
        // Check if Spatie Permission package is installed
        $permissionClass = \Spatie\Permission\Models\Permission::class;
        $roleClass = \Spatie\Permission\Models\Role::class;
        
        if (! class_exists($permissionClass)) {
            $this->command->info('Spatie Permission package not installed. Skipping permission creation.');

            return;
        }

        try {
            // Create or update the permission
            $selectNodesPermission = $permissionClass::firstOrCreate(
                ['name' => 'configs.select_panel_nodes'],
                ['guard_name' => 'web']
            );

            $this->command->info("Permission 'configs.select_panel_nodes' created/updated successfully.");
            $this->command->warn('NOTE: This permission is informational only. Eylandoo node selector visibility is based on panel_type, not permissions.');

            // Assign to admin and reseller roles
            // Note: These role names are standard in Laravel/Spatie Permission setups
            $rolesToAssign = ['super-admin', 'admin', 'reseller'];
            $assignedRoles = [];

            foreach ($rolesToAssign as $roleName) {
                $role = $roleClass::where('name', $roleName)->where('guard_name', 'web')->first();
                if ($role) {
                    if (! $role->hasPermissionTo($selectNodesPermission)) {
                        $role->givePermissionTo($selectNodesPermission);
                        $assignedRoles[] = $roleName;
                    }
                }
            }

            if (! empty($assignedRoles)) {
                $this->command->info('Permission assigned to roles: '.implode(', ', $assignedRoles));
            } else {
                $this->command->warn("No 'admin', 'super-admin', or 'reseller' roles found. Please assign the permission manually if needed.");
            }

            // Clear permission cache
            app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
            $this->command->info('Permission cache cleared.');

            $this->command->info('✓ Seeding completed successfully!');
            $this->command->info('  Next steps:');
            $this->command->info('  1. If using Filament Shield, run: php artisan shield:cache');
            $this->command->info('  2. Re-login to refresh user permissions');
            $this->command->info('  3. Remember: This permission does NOT control visibility - it\'s for auditing only');

        } catch (\Exception $e) {
            $this->command->error('Error during seeding: '.$e->getMessage());
            // Log full error details to Laravel log file for debugging
            Log::error('EylandooNodeSelectionPermissionSeeder failed', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            $this->command->info('Full error details have been logged to storage/logs/');
        }
    }
}
