<?php

namespace App\Policies;

use App\Enums\Permiso;
use App\Models\BankAccount;
use App\Models\User;

/**
 * Son las cuentas de la empresa y quedan a la vista de todo cliente que va a
 * transferir: no basta con poder configurar eventos. Solo Administración.
 */
class BankAccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permiso::GestionarCuentasBancarias->value);
    }

    public function view(User $user, BankAccount $bankAccount): bool
    {
        return $user->can(Permiso::GestionarCuentasBancarias->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permiso::GestionarCuentasBancarias->value);
    }

    public function update(User $user, BankAccount $bankAccount): bool
    {
        return $user->can(Permiso::GestionarCuentasBancarias->value);
    }

    public function delete(User $user, BankAccount $bankAccount): bool
    {
        return $user->can(Permiso::GestionarCuentasBancarias->value);
    }
}
