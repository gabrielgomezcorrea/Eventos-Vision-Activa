<?php

namespace App\Policies;

use App\Enums\Permiso;
use App\Models\User;

/** Una invitación regala un cupo: solo quien tiene el permiso de descuentos (Administración). */
class InvitationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permiso::GestionarDescuentos->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permiso::GestionarDescuentos->value);
    }

    public function update(User $user): bool
    {
        return $user->can(Permiso::GestionarDescuentos->value);
    }
}
