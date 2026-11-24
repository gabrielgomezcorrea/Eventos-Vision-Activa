<?php

namespace App\Policies;

use App\Enums\Permiso;
use App\Models\User;

/** Certificates carry personal data and email people: Administración and Coordinación. */
class CertificatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permiso::GestionarCertificados->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permiso::GestionarCertificados->value);
    }

    public function update(User $user): bool
    {
        return $user->can(Permiso::GestionarCertificados->value);
    }

    public function delete(User $user): bool
    {
        return $user->can(Permiso::GestionarCertificados->value);
    }
}
