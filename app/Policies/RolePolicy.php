<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Role;

class RolePolicy
{
    public function viewAny(User $user)
    {
        return $user->isOwner();
    }

    public function create(User $user)
    {
        return $user->isOwner();
    }

    public function delete(User $user, Role $role)
    {
        // Owner can't delete owner-role
        if ($role->name === 'owner') {
            return false;
        }

        return $user->isOwner();
    }

    public function update(User $user, Role $role)
    {
        return $user->isOwner();
    }
}
