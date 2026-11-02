<?php

namespace App\Policies;

use App\Models\Coverage;
use App\Models\User;

class CoveragePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && array_key_exists($user->role, User::ROLES);
    }

    public function view(User $user, Coverage $coverage): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Coverage $coverage): bool
    {
        return $this->viewAny($user);
    }

    public function transfer(User $user, Coverage $coverage): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, Coverage $coverage): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
