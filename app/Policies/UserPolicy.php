<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /**
     * Only admin or owner may view list of users
     */
    public function viewAny(User $user)
    {
        return $user->isAdmin();
    }

    /**
     * Users can view only themselves unless admin/owner
     */
    public function view(User $user, User $target)
    {
        return $user->id === $target->id || $user->isAdmin();
    }

    /**
     * Only admin or owner can delete users
     */
    public function delete(User $user, User $target)
    {
        if ($user->isOwner()) return true;

        // Admin cannot delete owner/admin
        if ($target->role->name === 'owner' || $target->role->name === 'admin') {
            return false;
        }

        return $user->isAdmin();
    }

    /**
     * Only admin or owner may change role
     */
    public function changeRole(User $user)
    {
        return $user->isAdmin();
    }

    /**
     * Users may update only their profile (unless admin)
     */
    public function update(User $user, User $target)
    {
        return $user->id === $target->id || $user->isAdmin();
    }
}
