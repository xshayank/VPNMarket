<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncPermissions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'permissions:sync {--dry-run : Show what would be done without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync permissions to database and assign to roles (idempotent)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
            $this->newLine();
        }

        // Check if Spatie Permission package is installed
        if (!class_exists(\Spatie\Permission\Models\Permission::class)) {
            $this->error('Spatie Permission package not installed. Cannot sync permissions.');
            return 1;
        }

        try {
            $permissionClass = \Spatie\Permission\Models\Permission::class;
            $roleClass = \Spatie\Permission\Models\Role::class;

            // Define permissions to create
            $permissionsToCreate = [
                'configs.reset_usage' => ['super-admin', 'admin'],
                'configs.reset_usage_own' => ['reseller'],
            ];

            $created = 0;
            $updated = 0;
            $assigned = 0;

            foreach ($permissionsToCreate as $permissionName => $roleNames) {
                // Check if permission exists
                $permission = $permissionClass::where('name', $permissionName)
                    ->where('guard_name', 'web')
                    ->first();

                if (!$permission) {
                    if (!$dryRun) {
                        $permission = $permissionClass::create([
                            'name' => $permissionName,
                            'guard_name' => 'web',
                        ]);
                    }
                    $this->info("  [CREATE] Permission: {$permissionName}");
                    $created++;
                } else {
                    $this->comment("  [EXISTS] Permission: {$permissionName}");
                    $updated++;
                }

                // Assign to roles
                foreach ($roleNames as $roleName) {
                    $role = $roleClass::where('name', $roleName)
                        ->where('guard_name', 'web')
                        ->first();

                    if ($role) {
                        if ($permission && !$role->hasPermissionTo($permission)) {
                            if (!$dryRun) {
                                $role->givePermissionTo($permission);
                            }
                            $this->info("    [ASSIGN] {$permissionName} → {$roleName}");
                            $assigned++;
                        } else {
                            $this->comment("    [EXISTS] {$permissionName} → {$roleName}");
                        }
                    } else {
                        $this->warn("    [SKIP] Role '{$roleName}' not found");
                    }
                }
            }

            // Clear permission cache if not dry run
            if (!$dryRun) {
                app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
                $this->info("Permission cache cleared.");
            }

            $this->newLine();
            $this->info('Sync Summary:');
            $this->table(
                ['Action', 'Count'],
                [
                    ['Permissions Created', $created],
                    ['Permissions Existed', $updated],
                    ['Assignments Made', $assigned],
                ]
            );

            if ($dryRun) {
                $this->newLine();
                $this->warn('DRY RUN COMPLETE - No changes were made');
                $this->info('Run without --dry-run to apply changes: php artisan permissions:sync');
            } else {
                $this->newLine();
                $this->info('✓ Permissions synced successfully!');
                $this->info('  Note: Users may need to re-login to refresh permissions.');
            }

            return 0;

        } catch (\Exception $e) {
            $this->error("Error syncing permissions: " . $e->getMessage());
            Log::error("SyncPermissions command failed", [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return 1;
        }
    }
}
