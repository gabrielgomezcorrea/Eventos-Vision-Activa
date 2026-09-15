<?php

namespace App\Policies;

use App\Enums\Permiso;
use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permiso::VerOrdenes->value);
    }

    public function view(User $user, Order $order): bool
    {
        return $user->can(Permiso::VerOrdenes->value);
    }

    public function verCredenciales(User $user, Order $order): bool
    {
        return $user->can(Permiso::VerCredenciales->value);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Order $order): bool
    {
        return $user->can(Permiso::GestionarOrdenes->value);
    }

    public function delete(User $user, Order $order): bool
    {
        return false;
    }
}
