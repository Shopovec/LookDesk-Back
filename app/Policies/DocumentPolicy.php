<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Document;

class DocumentPolicy
{
    public function viewAny(User $user)
    {
        return true;
    }

    public function view(User $user, Document $document)
    {
        return true;
    }

    public function create(User $user)
    {
        return $user->isEditor();
    }

    public function update(User $user, Document $document)
    {
        // owner & admin — всегда
        if ($user->isAdmin() || $user->isOwner()) return true;

        // editor может редактировать только свои
        if ($user->isEditor() && $document->created_by == $user->id) {
            return true;
        }

        return false;
    }

    public function delete(User $user, Document $document)
    {
        // Only admin or owner
        return $user->isAdmin();
    }
}
