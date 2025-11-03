<?php

namespace App\Policies;

use App\Models\Reseller;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ResellerPolicy
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
        // Admins can view all resellers
        if ($user->hasPermissionTo('resellers.view_any')) {
            return true;
        }

        // Resellers can view only themselves
        if ($user->hasPermissionTo('resellers.view_own')) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Reseller $reseller): bool
    {
        // Admins can view any reseller
        if ($user->hasPermissionTo('resellers.view')) {
            return true;
        }

        // Resellers can only view their own record
        if ($user->hasPermissionTo('resellers.view_own') && $reseller->user_id === $user->id) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasPermissionTo('resellers.create');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Reseller $reseller): bool
    {
        // Admins can update any reseller
        if ($user->hasPermissionTo('resellers.update')) {
            return true;
        }

        // Resellers can only update their own record
        if ($user->hasPermissionTo('resellers.update_own') && $reseller->user_id === $user->id) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Reseller $reseller): bool
    {
        return $user->hasPermissionTo('resellers.delete');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Reseller $reseller): bool
    {
        return $user->hasPermissionTo('resellers.delete');
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Reseller $reseller): bool
    {
        return $user->hasPermissionTo('resellers.delete');
    }

    /**
     * Determine whether the user can enable the reseller.
     */
    public function enable(User $user, Reseller $reseller): bool
    {
        return $user->hasPermissionTo('resellers.enable');
    }

    /**
     * Determine whether the user can disable the reseller.
     */
    public function disable(User $user, Reseller $reseller): bool
    {
        return $user->hasPermissionTo('resellers.disable');
    }

    /**
     * Determine whether the user can manually enable the reseller.
     */
    public function manualEnable(User $user, Reseller $reseller): bool
    {
        return $user->hasPermissionTo('resellers.manual_enable');
    }
}
