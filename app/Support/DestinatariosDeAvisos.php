<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * A quién le llega cada aviso interno: usuarios activos con la casilla
 * encendida y el permiso de lo que avisa todavía vigente (si le quitaron el rol
 * después de encenderla, no recibe datos que ya no puede ver).
 */
class DestinatariosDeAvisos
{
    /**
     * @param  string  $columna  una clave de User::AVISOS
     * @return Collection<int, User>
     */
    public static function para(string $columna): Collection
    {
        abort_unless(array_key_exists($columna, User::AVISOS), 500, "Aviso desconocido: {$columna}");

        $permiso = User::AVISOS[$columna]['permiso']->value;

        return User::query()
            ->where('is_active', true)
            ->where($columna, true)
            ->get()
            ->filter(fn (User $usuario): bool => $usuario->can($permiso))
            ->values();
    }
}
