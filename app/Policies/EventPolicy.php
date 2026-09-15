<?php

namespace App\Policies;

use App\Enums\Permiso;
use App\Models\Event;
use App\Models\User;

class EventPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permiso::VerEventos->value);
    }

    public function view(User $user, Event $event): bool
    {
        return $user->can(Permiso::VerEventos->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permiso::GestionarEventos->value);
    }

    public function update(User $user, Event $event): bool
    {
        return $user->can(Permiso::GestionarEventos->value);
    }

    public function delete(User $user, Event $event): bool
    {
        return $user->can(Permiso::GestionarEventos->value);
    }
}
