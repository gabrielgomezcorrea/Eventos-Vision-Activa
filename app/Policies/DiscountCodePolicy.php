<?php

namespace App\Policies;

use App\Enums\Permiso;
use App\Models\User;

/**
 * Los códigos regalan cupos: solo quien tiene el permiso de descuentos
 * (Administración). Coordinación configura eventos pero no los crea.
 */
class DiscountCodePolicy
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
