<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class MigrateUsersToRoles extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rbac:migrate-users {--dry-run : Show what would be done without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate existing users to the new role-based access control system';

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

        // Check if roles exist
        $superAdminRole = Role::where('name', 'super-admin')->first();
        $adminRole = Role::where('name', 'admin')->first();
        $resellerRole = Role::where('name', 'reseller')->first();
        $userRole = Role::where('name', 'user')->first();

        if (!$superAdminRole || !$adminRole || !$resellerRole || !$userRole) {
            $this->error('Roles not found! Please run: php artisan db:seed --class=RbacSeeder');
            return 1;
        }

        // Get users without roles
        $usersWithoutRoles = User::doesntHave('roles')->get();

        if ($usersWithoutRoles->isEmpty()) {
            $this->info('All users already have roles assigned!');
            return 0;
        }

        $this->info("Found {$usersWithoutRoles->count()} users without roles");
        $this->newLine();

        $superAdminCount = 0;
        $adminCount = 0;
        $resellerCount = 0;
        $userCount = 0;
        $errorCount = 0;

        $progressBar = $this->output->createProgressBar($usersWithoutRoles->count());
        $progressBar->start();

        foreach ($usersWithoutRoles as $user) {
            $role = null;
            $roleName = '';

            try {
                // Determine role
                if ($user->is_super_admin) {
                    $role = $superAdminRole;
                    $roleName = 'super-admin';
                    $superAdminCount++;
                } elseif ($user->is_admin) {
                    $role = $adminRole;
                    $roleName = 'admin';
                    $adminCount++;
                } elseif ($user->reseller()->exists()) {
                    $role = $resellerRole;
                    $roleName = 'reseller';
                    $resellerCount++;
                } else {
                    $role = $userRole;
                    $roleName = 'user';
                    $userCount++;
                }

                if (!$dryRun && $role) {
                    DB::beginTransaction();
                    $user->assignRole($role);
                    DB::commit();
                }

                $progressBar->advance();
            } catch (\Exception $e) {
                DB::rollBack();
                $errorCount++;
                $this->newLine();
                $this->error("Failed to assign role to user {$user->id} ({$user->email}): {$e->getMessage()}");
            }
        }

        $progressBar->finish();
        $this->newLine(2);

        // Display summary
        $this->info('Migration Summary:');
        $this->table(
            ['Role', 'Count'],
            [
                ['Super Admin', $superAdminCount],
                ['Admin', $adminCount],
                ['Reseller', $resellerCount],
                ['User', $userCount],
                ['Errors', $errorCount],
            ]
        );

        if ($dryRun) {
            $this->newLine();
            $this->warn('DRY RUN COMPLETE - No changes were made');
            $this->info('Run without --dry-run to apply changes: php artisan rbac:migrate-users');
        } else {
            $this->newLine();
            $this->info('Migration complete!');
        }

        return 0;
    }
}
