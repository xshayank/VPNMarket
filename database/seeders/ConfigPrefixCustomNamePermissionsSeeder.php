<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

class ConfigPrefixCustomNamePermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * This seeder creates the 'configs.set_prefix' and 'configs.set_custom_name' permissions
     * and assigns them to appropriate roles if Spatie Permission package is installed.
     *
     * To run this seeder:
     * php artisan db:seed --class=ConfigPrefixCustomNamePermissionsSeeder
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
            $setPrefixPermission = $permissionClass::firstOrCreate(
                ['name' => 'configs.set_prefix'],
                ['guard_name' => 'web']
            );

            $setCustomNamePermission = $permissionClass::firstOrCreate(
                ['name' => 'configs.set_custom_name'],
                ['guard_name' => 'web']
            );

            $this->command->info("Permission 'configs.set_prefix' created/updated successfully.");
            $this->command->info("Permission 'configs.set_custom_name' created/updated successfully.");

            // Try to assign to appropriate roles if they exist
            $resellerRoles = ['reseller'];
            $superAdminRoles = ['super-admin'];
            $assignedRoles = [];

            // Assign set_prefix to reseller role
            foreach ($resellerRoles as $roleName) {
                $role = $roleClass::where('name', $roleName)->where('guard_name', 'web')->first();
                if ($role) {
                    if (! $role->hasPermissionTo($setPrefixPermission)) {
                        $role->givePermissionTo($setPrefixPermission);
                        $assignedRoles[] = "$roleName (set_prefix)";
                    }
                }
            }

            // Assign set_custom_name to super-admin role
            foreach ($superAdminRoles as $roleName) {
                $role = $roleClass::where('name', $roleName)->where('guard_name', 'web')->first();
                if ($role) {
                    if (! $role->hasPermissionTo($setCustomNamePermission)) {
                        $role->givePermissionTo($setCustomNamePermission);
                        $assignedRoles[] = "$roleName (set_custom_name)";
                    }
                }
            }

            if (! empty($assignedRoles)) {
                $this->command->info('Permissions assigned to roles: '.implode(', ', $assignedRoles));
            } else {
                $this->command->warn("No 'super-admin' or 'reseller' roles found. Please assign the permissions manually.");
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
            Log::error('ConfigPrefixCustomNamePermissionsSeeder failed', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            $this->command->info('Full error details have been logged to storage/logs/');
        }
    }
}
