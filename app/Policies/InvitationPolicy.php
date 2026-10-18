<?php

namespace App\Policies;

use App\Enums\Permiso;
use App\Models\User;

/** Una invitación regala un cupo: solo quien tiene el permiso de invitaciones (Administración y Coordinación). */
class InvitationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permiso::GestionarInvitaciones->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permiso::GestionarInvitaciones->value);
    }

    public function update(User $user): bool
    {
        return $user->can(Permiso::GestionarInvitaciones->value);
    }
}
