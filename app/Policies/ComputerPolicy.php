<?php

namespace App\Policies;

use App\Models\Computer;
use App\Models\User;

class ComputerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && array_key_exists($user->role, User::ROLES);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Computer $computer): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, Computer $computer): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
