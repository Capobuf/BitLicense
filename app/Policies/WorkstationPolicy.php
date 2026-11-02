<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Workstation;

class WorkstationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && array_key_exists($user->role, User::ROLES);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Workstation $workstation): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, Workstation $workstation): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
