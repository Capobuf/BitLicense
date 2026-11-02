<?php

namespace App\Policies;

use App\Models\Endpoint;
use App\Models\User;

class EndpointPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && array_key_exists($user->role, User::ROLES);
    }

    public function view(User $user, Endpoint $endpoint): bool
    {
        return $this->viewAny($user);
    }

    public function link(User $user, Endpoint $endpoint): bool
    {
        return $this->viewAny($user) && $endpoint->is_present && $endpoint->company->is_present && $endpoint->workstation === null;
    }

    public function synchronize(User $user): bool
    {
        return $user->isAdministrator();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Endpoint $endpoint): bool
    {
        return false;
    }

    public function delete(User $user, Endpoint $endpoint): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
