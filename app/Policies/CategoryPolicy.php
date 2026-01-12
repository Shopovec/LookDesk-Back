<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Category;

class CategoryPolicy
{
    public function viewAny(User $user)
    {
        return true; // открыто всем
    }

    public function view(User $user, Category $category)
    {
        return true;
    }

    public function create(User $user)
    {
        return $user->isEditor();
    }

    public function update(User $user, Category $category)
    {
        return $user->isEditor();
    }

    public function delete(User $user, Category $category)
    {
        // System categories можно удалять только owner
        if ($category->is_system) {
            return $user->isOwner();
        }

        return $user->isEditor();
    }
}
