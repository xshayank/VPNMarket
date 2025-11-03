<?php

namespace App\Policies;

use App\Models\ResellerConfig;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ResellerConfigPolicy
{
    /**
     * Perform pre-authorization checks.
     */
    public function before(User $user, string $ability): bool|null
    {
        // Super admins can do everything
        if ($user->isSuperAdmin()) {
            return true;
        }

        return null;
    }

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        // Admins can view all configs
        if ($user->hasPermissionTo('configs.view_any')) {
            return true;
        }

        // Resellers can view their own configs
        if ($user->hasPermissionTo('configs.view_own') && $user->reseller()->exists()) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, ResellerConfig $resellerConfig): bool
    {
        // Admins can view any config
        if ($user->hasPermissionTo('configs.view')) {
            return true;
        }

        // Resellers can only view their own configs
        if ($user->hasPermissionTo('configs.view_own') && $user->reseller) {
            return $resellerConfig->reseller_id === $user->reseller->id;
        }

        return false;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        // Admins can create configs for any reseller
        if ($user->hasPermissionTo('configs.create')) {
            return true;
        }

        // Resellers can create their own configs
        if ($user->hasPermissionTo('configs.create_own') && $user->reseller()->exists()) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, ResellerConfig $resellerConfig): bool
    {
        // Admins can update any config
        if ($user->hasPermissionTo('configs.update')) {
            return true;
        }

        // Resellers can only update their own configs
        if ($user->hasPermissionTo('configs.update_own') && $user->reseller) {
            return $resellerConfig->reseller_id === $user->reseller->id;
        }

        return false;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, ResellerConfig $resellerConfig): bool
    {
        // Admins can delete any config
        if ($user->hasPermissionTo('configs.delete')) {
            return true;
        }

        // Resellers can only delete their own configs
        if ($user->hasPermissionTo('configs.delete_own') && $user->reseller) {
            return $resellerConfig->reseller_id === $user->reseller->id;
        }

        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, ResellerConfig $resellerConfig): bool
    {
        // Same logic as delete
        return $this->delete($user, $resellerConfig);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, ResellerConfig $resellerConfig): bool
    {
        // Only admins can force delete
        return $user->hasPermissionTo('configs.delete');
    }

    /**
     * Determine whether the user can enable the config.
     */
    public function enable(User $user, ResellerConfig $resellerConfig): bool
    {
        return $user->hasPermissionTo('configs.enable');
    }

    /**
     * Determine whether the user can disable the config.
     */
    public function disable(User $user, ResellerConfig $resellerConfig): bool
    {
        return $user->hasPermissionTo('configs.disable');
    }

    /**
     * Determine whether the user can sync usage for the config.
     */
    public function syncUsage(User $user, ResellerConfig $resellerConfig): bool
    {
        return $user->hasPermissionTo('configs.sync_usage');
    }

    /**
     * Determine whether the user can reset usage for the config.
     */
    public function resetUsage(User $user, ResellerConfig $resellerConfig): bool
    {
        // Admins can reset any config
        if ($user->hasPermissionTo('configs.reset_usage')) {
            return true;
        }

        // Resellers can only reset their own configs
        if ($user->hasPermissionTo('configs.reset_usage_own') && $user->reseller) {
            return $resellerConfig->reseller_id === $user->reseller->id;
        }

        return false;
    }
}
