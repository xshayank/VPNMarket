<?php

namespace App\Policies;

use App\Models\Panel;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class PanelPolicy
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
        return $user->hasPermissionTo('panels.view_any');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Panel $panel): bool
    {
        // Admins can view any panel
        if ($user->hasPermissionTo('panels.view')) {
            return true;
        }

        // Resellers can view panels they're associated with
        if ($user->reseller && $user->reseller->panel_id === $panel->id) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasPermissionTo('panels.create');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Panel $panel): bool
    {
        return $user->hasPermissionTo('panels.update');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Panel $panel): bool
    {
        return $user->hasPermissionTo('panels.delete');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Panel $panel): bool
    {
        return $user->hasPermissionTo('panels.delete');
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Panel $panel): bool
    {
        return $user->hasPermissionTo('panels.delete');
    }

    /**
     * Determine whether the user can test connection to the panel.
     */
    public function testConnection(User $user, Panel $panel): bool
    {
        return $user->hasPermissionTo('panels.test_connection');
    }
}
