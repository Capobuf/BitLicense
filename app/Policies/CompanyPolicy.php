<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\User;

class CompanyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && array_key_exists($user->role, User::ROLES);
    }

    public function update(User $user, Company $company): bool
    {
        return $this->viewAny($user);
    }

    public function synchronize(User $user): bool
    {
        return $user->isAdministrator();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function delete(User $user, Company $company): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
