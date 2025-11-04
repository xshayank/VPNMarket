<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

class PermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * This seeder creates the 'configs.reset_usage', 'configs.reset_usage_own',
     * and 'panels.use.eylandoo' permissions and assigns them to appropriate roles
     * if Spatie Permission package is installed.
     *
     * To run this seeder:
     * php artisan db:seed --class=PermissionsSeeder
     *
     * If Filament Shield is installed, refresh cache after seeding:
     * php artisan shield:cache
     */
    public function run(): void
    {
        // Check if Spatie Permission package is installed
        if (! class_exists(\Spatie\Permission\Models\Permission::class)) {
            $this->command->info('Spatie Permission package not installed. Skipping permission creation.');

            return;
        }

        try {
            $permissionClass = \Spatie\Permission\Models\Permission::class;
            $roleClass = \Spatie\Permission\Models\Role::class;

            // Create or update the permissions
            $resetUsagePermission = $permissionClass::firstOrCreate(
                ['name' => 'configs.reset_usage'],
                ['guard_name' => 'web']
            );

            $resetUsageOwnPermission = $permissionClass::firstOrCreate(
                ['name' => 'configs.reset_usage_own'],
                ['guard_name' => 'web']
            );

            $eylandooPanelPermission = $permissionClass::firstOrCreate(
                ['name' => 'panels.use.eylandoo'],
                ['guard_name' => 'web']
            );

            $this->command->info("Permission 'configs.reset_usage' created/updated successfully.");
            $this->command->info("Permission 'configs.reset_usage_own' created/updated successfully.");
            $this->command->info("Permission 'panels.use.eylandoo' created/updated successfully.");

            // Try to assign to admin roles if they exist
            $adminRoles = ['super-admin', 'admin'];
            $resellerRoles = ['reseller'];
            $assignedRoles = [];

            // Assign full reset_usage to super-admin and admin
            foreach ($adminRoles as $roleName) {
                $role = $roleClass::where('name', $roleName)->where('guard_name', 'web')->first();
                if ($role) {
                    if (! $role->hasPermissionTo($resetUsagePermission)) {
                        $role->givePermissionTo($resetUsagePermission);
                        $assignedRoles[] = $roleName;
                    }
                    // Also assign eylandoo panel permission to admins
                    if (! $role->hasPermissionTo($eylandooPanelPermission)) {
                        $role->givePermissionTo($eylandooPanelPermission);
                    }
                }
            }

            // Assign reset_usage_own to reseller
            foreach ($resellerRoles as $roleName) {
                $role = $roleClass::where('name', $roleName)->where('guard_name', 'web')->first();
                if ($role) {
                    if (! $role->hasPermissionTo($resetUsageOwnPermission)) {
                        $role->givePermissionTo($resetUsageOwnPermission);
                        $assignedRoles[] = $roleName;
                    }
                }
            }

            if (! empty($assignedRoles)) {
                $this->command->info('Permissions assigned to roles: '.implode(', ', $assignedRoles));
            } else {
                $this->command->warn("No 'admin', 'super-admin', or 'reseller' roles found. Please assign the permissions manually.");
            }

            // Clear permission cache
            app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
            $this->command->info('Permission cache cleared.');

            $this->command->info('✓ Seeding completed successfully!');
            $this->command->info('  Next steps:');
            $this->command->info('  1. If using Filament Shield, run: php artisan shield:cache');
            $this->command->info('  2. Re-login to refresh user permissions');

        } catch (\Exception $e) {
            $this->command->error('Error during seeding: '.$e->getMessage());
            // Log full error details to Laravel log file for debugging
            Log::error('PermissionsSeeder failed', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            $this->command->info('Full error details have been logged to storage/logs/');
        }
    }
}
