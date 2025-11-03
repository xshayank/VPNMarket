<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class AttachPanelConfigsPermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * 
     * This seeder creates the 'manage.panel-config-imports' permission
     * and assigns it to admin roles if Spatie Permission package is installed.
     * 
     * To run this seeder:
     * php artisan db:seed --class=AttachPanelConfigsPermissionSeeder
     * 
     * If Filament Shield is installed, refresh cache after seeding:
     * php artisan shield:cache
     */
    public function run(): void
    {
        // Check if Spatie Permission package is installed
        if (!class_exists(\Spatie\Permission\Models\Permission::class)) {
            $this->command->info('Spatie Permission package not installed. Skipping permission creation.');
            $this->command->info('Users with is_admin=true will have access to the page.');
            return;
        }

        try {
            $permissionClass = \Spatie\Permission\Models\Permission::class;
            $roleClass = \Spatie\Permission\Models\Role::class;

            // Create or update the permission
            $permission = $permissionClass::firstOrCreate(
                ['name' => 'manage.panel-config-imports'],
                [
                    'guard_name' => 'web',
                ]
            );

            $this->command->info("Permission 'manage.panel-config-imports' created/updated successfully.");

            // Try to assign to admin roles if they exist
            $adminRoles = ['super-admin', 'admin'];
            $assignedRoles = [];

            foreach ($adminRoles as $roleName) {
                $role = $roleClass::where('name', $roleName)->first();
                if ($role) {
                    if (!$role->hasPermissionTo($permission)) {
                        $role->givePermissionTo($permission);
                        $assignedRoles[] = $roleName;
                    }
                }
            }

            if (!empty($assignedRoles)) {
                $this->command->info("Permission assigned to roles: " . implode(', ', $assignedRoles));
            } else {
                $this->command->warn("No 'admin' or 'super-admin' roles found. Please assign the permission manually.");
            }

            $this->command->info("✓ Seeding completed successfully!");
            $this->command->info("  Next steps:");
            $this->command->info("  1. If using Filament Shield, run: php artisan shield:cache");
            $this->command->info("  2. Re-login to refresh user permissions");
            $this->command->info("  3. Visit /admin/attach-panel-configs-to-reseller to verify access");

        } catch (\Exception $e) {
            $this->command->error("Error during seeding: " . $e->getMessage());
            $this->command->error("Stack trace: " . $e->getTraceAsString());
        }
    }
}
