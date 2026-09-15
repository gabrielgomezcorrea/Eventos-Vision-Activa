<?php

namespace App\Policies;

use App\Enums\Permiso;
use App\Models\Payment;
use App\Models\User;

class PaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permiso::VerComprobantes->value);
    }

    public function view(User $user, Payment $payment): bool
    {
        return $user->can(Permiso::VerComprobantes->value);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Payment $payment): bool
    {
        return $user->can(Permiso::ValidarPagos->value);
    }

    public function delete(User $user, Payment $payment): bool
    {
        return false;
    }
}
