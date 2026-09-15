<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Sube o baja un elemento un lugar dentro de su lista.
 *
 * Deja las posiciones correlativas desde 1: las jornadas y accesos que venían
 * del sistema anterior podían tener posiciones repetidas, y con repetidos
 * intercambiar dos números no mueve nada.
 */
class Reordenar
{
    /**
     * @template TModel of Model
     *
     * @param  Collection<int, TModel>  $elementos  Ya ordenados como se muestran.
     * @param  TModel  $elemento
     */
    public static function mover(Collection $elementos, Model $elemento, string $direccion): void
    {
        $lista = $elementos->values()->all();
        $indice = collect($lista)->search(fn (Model $item): bool => $item->is($elemento));

        if ($indice === false) {
            return;
        }

        $destino = $direccion === 'arriba' ? $indice - 1 : $indice + 1;

        if (! isset($lista[$destino])) {
            return;
        }

        [$lista[$indice], $lista[$destino]] = [$lista[$destino], $lista[$indice]];

        foreach ($lista as $posicion => $item) {
            if ($item->getAttribute('position') !== $posicion + 1) {
                $item->update(['position' => $posicion + 1]);
            }
        }
    }
}
