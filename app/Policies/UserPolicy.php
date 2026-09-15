<?php

namespace App\Policies;

use App\Enums\Permiso;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permiso::GestionarUsuarios->value);
    }

    public function view(User $user, User $model): bool
    {
        return $user->can(Permiso::GestionarUsuarios->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permiso::GestionarUsuarios->value);
    }

    public function update(User $user, User $model): bool
    {
        return $user->can(Permiso::GestionarUsuarios->value);
    }

    /** Nadie puede eliminarse a sí mismo: dejaría el sistema sin administrador. */
    public function delete(User $user, User $model): bool
    {
        return $user->can(Permiso::GestionarUsuarios->value) && ! $user->is($model);
    }
}
