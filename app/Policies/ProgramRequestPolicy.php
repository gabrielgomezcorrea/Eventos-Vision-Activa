<?php

namespace App\Policies;

use App\Enums\Permiso;
use App\Models\ProgramRequest;
use App\Models\User;

class ProgramRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permiso::VerSolicitudes->value);
    }

    public function view(User $user, ProgramRequest $request): bool
    {
        return $user->can(Permiso::VerSolicitudes->value);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, ProgramRequest $request): bool
    {
        return $user->can(Permiso::GestionarOrdenes->value);
    }

    public function delete(User $user, ProgramRequest $request): bool
    {
        return $user->can(Permiso::GestionarOrdenes->value);
    }
}
