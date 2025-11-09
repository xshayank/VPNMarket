<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class WalletTopUpPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Create wallet top-up transaction permissions
        $permissions = [
            'view_any_wallet::top::up::transaction',
            'view_wallet::top::up::transaction',
            'create_wallet::top::up::transaction',
            'update_wallet::top::up::transaction',
            'delete_wallet::top::up::transaction',
            'delete_any_wallet::top::up::transaction',
            'force_delete_wallet::top::up::transaction',
            'force_delete_any_wallet::top::up::transaction',
            'restore_wallet::top::up::transaction',
            'restore_any_wallet::top::up::transaction',
            'replicate_wallet::top::up::transaction',
            'reorder_wallet::top::up::transaction',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        // Assign permissions to super-admin role
        $superAdminRole = Role::where('name', 'super-admin')->where('guard_name', 'web')->first();
        if ($superAdminRole) {
            $superAdminRole->givePermissionTo($permissions);
            $this->command->info('Permissions assigned to super-admin role.');
        }

        // Assign permissions to admin role
        $adminRole = Role::where('name', 'admin')->where('guard_name', 'web')->first();
        if ($adminRole) {
            $adminRole->givePermissionTo($permissions);
            $this->command->info('Permissions assigned to admin role.');
        }

        $this->command->info('Wallet top-up transaction permissions created and assigned successfully.');
    }
}
