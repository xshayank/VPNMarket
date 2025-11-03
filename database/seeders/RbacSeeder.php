<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RbacSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Create permissions
        $permissions = [
            // Panel permissions
            'panels.view_any',
            'panels.view',
            'panels.create',
            'panels.update',
            'panels.delete',
            'panels.test_connection',
            
            // Reseller permissions
            'resellers.view_any',
            'resellers.view',
            'resellers.view_own',
            'resellers.create',
            'resellers.update',
            'resellers.update_own',
            'resellers.delete',
            'resellers.enable',
            'resellers.disable',
            'resellers.manual_enable',
            
            // ResellerConfig permissions
            'configs.view_any',
            'configs.view',
            'configs.view_own',
            'configs.create',
            'configs.create_own',
            'configs.update',
            'configs.update_own',
            'configs.delete',
            'configs.delete_own',
            'configs.enable',
            'configs.disable',
            'configs.sync_usage',
            
            // User management permissions
            'users.view_any',
            'users.view',
            'users.create',
            'users.update',
            'users.delete',
            
            // Order permissions
            'orders.view_any',
            'orders.view',
            'orders.view_own',
            'orders.create',
            'orders.update',
            'orders.delete',
            
            // Plan permissions
            'plans.view_any',
            'plans.view',
            'plans.create',
            'plans.update',
            'plans.delete',
            
            // Billing permissions
            'billing.view',
            'billing.manage',
            
            // Settings permissions
            'settings.view',
            'settings.manage',
            
            // Audit log permissions
            'audits.view_any',
            'audits.view',
            
            // Custom page permissions
            'manage.panel-config-imports',
            'manage.email-center',
            'manage.reseller-enforcement-settings',
            'manage.theme-settings',
            'manage.module-manager',
            
            // API permissions
            'api.panels.read',
            'api.panels.write',
            'api.configs.read',
            'api.configs.read_own',
            'api.configs.write',
            'api.configs.write_own',
            'api.audit-logs.read',
            
            // Promo code permissions
            'promo-codes.view_any',
            'promo-codes.view',
            'promo-codes.create',
            'promo-codes.update',
            'promo-codes.delete',
            
            // Inbound permissions
            'inbounds.view_any',
            'inbounds.view',
            'inbounds.create',
            'inbounds.update',
            'inbounds.delete',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        // Create roles
        $superAdminRole = Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $resellerRole = Role::firstOrCreate(['name' => 'reseller', 'guard_name' => 'web']);
        $userRole = Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web']);

        // Super admin gets all permissions
        $superAdminRole->givePermissionTo(Permission::all());

        // Admin gets most permissions (excluding super-admin only features if any)
        $adminPermissions = [
            'panels.*',
            'resellers.*',
            'configs.*',
            'users.*',
            'orders.*',
            'plans.*',
            'billing.*',
            'settings.*',
            'audits.*',
            'manage.*',
            'api.panels.*',
            'api.configs.*',
            'api.audit-logs.*',
            'promo-codes.*',
            'inbounds.*',
        ];
        
        foreach ($adminPermissions as $pattern) {
            $perms = Permission::where('name', 'like', str_replace('*', '%', $pattern))->get();
            $adminRole->givePermissionTo($perms);
        }

        // Grant all Shield-generated permissions to admin
        $shieldPermissions = Permission::where(function ($query) {
            $query->where('name', 'like', 'view_%')
                  ->orWhere('name', 'like', 'create_%')
                  ->orWhere('name', 'like', 'update_%')
                  ->orWhere('name', 'like', 'delete_%')
                  ->orWhere('name', 'like', 'restore_%')
                  ->orWhere('name', 'like', 'replicate_%')
                  ->orWhere('name', 'like', 'reorder_%')
                  ->orWhere('name', 'like', 'force_delete_%')
                  ->orWhere('name', 'like', 'page_%')
                  ->orWhere('name', 'like', 'widget_%');
        })->get();
        
        $adminRole->givePermissionTo($shieldPermissions);

        // Reseller gets limited permissions
        $resellerPermissions = [
            'configs.view_own',
            'configs.create_own',
            'configs.update_own',
            'configs.delete_own',
            'configs.enable',
            'configs.disable',
            'configs.sync_usage',
            'resellers.view_own',
            'resellers.update_own',
            'orders.view_own',
            'api.configs.read_own',
            'api.configs.write_own',
        ];
        $resellerRole->givePermissionTo($resellerPermissions);

        // User gets minimal permissions
        $userPermissions = [
            'configs.view_own',
            'orders.view_own',
            'api.configs.read_own',
        ];
        $userRole->givePermissionTo($userPermissions);

        // Migrate existing users to new roles
        $this->migrateExistingUsers($superAdminRole, $adminRole, $resellerRole, $userRole);
    }

    /**
     * Migrate existing users to new role system
     */
    private function migrateExistingUsers($superAdminRole, $adminRole, $resellerRole, $userRole): void
    {
        $this->command->info('Migrating existing users to role system...');

        // Get users without roles
        $usersWithoutRoles = User::doesntHave('roles')->get();

        $superAdminCount = 0;
        $adminCount = 0;
        $resellerCount = 0;
        $userCount = 0;

        foreach ($usersWithoutRoles as $user) {
            DB::beginTransaction();
            try {
                // Check if user is super admin
                if ($user->is_super_admin) {
                    $user->assignRole($superAdminRole);
                    $superAdminCount++;
                }
                // Check if user is admin
                elseif ($user->is_admin) {
                    $user->assignRole($adminRole);
                    $adminCount++;
                }
                // Check if user has reseller relationship
                elseif ($user->reseller()->exists()) {
                    $user->assignRole($resellerRole);
                    $resellerCount++;
                }
                // Regular user
                else {
                    $user->assignRole($userRole);
                    $userCount++;
                }
                
                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                $this->command->error("Failed to assign role to user {$user->id}: {$e->getMessage()}");
            }
        }

        $this->command->info("Migration complete:");
        $this->command->info("  - Super Admins: {$superAdminCount}");
        $this->command->info("  - Admins: {$adminCount}");
        $this->command->info("  - Resellers: {$resellerCount}");
        $this->command->info("  - Users: {$userCount}");
    }
}
