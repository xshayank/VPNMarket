<?php

namespace App\Policies;

use App\Models\User;
use App\Models\PromoCode;
use Illuminate\Auth\Access\HandlesAuthorization;

class PromoCodePolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('view_any_promo::code');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, PromoCode $promoCode): bool
    {
        return $user->can('view_promo::code');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('create_promo::code');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, PromoCode $promoCode): bool
    {
        return $user->can('update_promo::code');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, PromoCode $promoCode): bool
    {
        return $user->can('delete_promo::code');
    }

    /**
     * Determine whether the user can bulk delete.
     */
    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any_promo::code');
    }

    /**
     * Determine whether the user can permanently delete.
     */
    public function forceDelete(User $user, PromoCode $promoCode): bool
    {
        return $user->can('force_delete_promo::code');
    }

    /**
     * Determine whether the user can permanently bulk delete.
     */
    public function forceDeleteAny(User $user): bool
    {
        return $user->can('force_delete_any_promo::code');
    }

    /**
     * Determine whether the user can restore.
     */
    public function restore(User $user, PromoCode $promoCode): bool
    {
        return $user->can('restore_promo::code');
    }

    /**
     * Determine whether the user can bulk restore.
     */
    public function restoreAny(User $user): bool
    {
        return $user->can('restore_any_promo::code');
    }

    /**
     * Determine whether the user can replicate.
     */
    public function replicate(User $user, PromoCode $promoCode): bool
    {
        return $user->can('replicate_promo::code');
    }

    /**
     * Determine whether the user can reorder.
     */
    public function reorder(User $user): bool
    {
        return $user->can('reorder_promo::code');
    }
}
