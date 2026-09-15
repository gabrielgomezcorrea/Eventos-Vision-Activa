<?php

namespace App\Actions;

use App\Models\Event;
use Illuminate\Support\Facades\DB;

/**
 * Asigna el folio correlativo de una orden, ej. SI-2026-0048.
 *
 * El correlativo vive en su propia tabla y se incrementa bloqueando una única
 * fila. Derivarlo de un max() sobre orders produce folios repetidos cuando dos
 * clientes confirman al mismo tiempo; el índice único de orders lo detectaría,
 * pero recién al fallar la escritura.
 *
 * La serie es por prefijo y año, no por evento: `orders.number` es único en
 * toda la tabla, así que dos eventos con el mismo prefijo deben compartir
 * correlativo. Para una serie aparte, se le cambia el prefijo al evento.
 *
 * Debe llamarse dentro de una transacción.
 */
class GenerarNumeroDeOrden
{
    public function __invoke(Event $event, ?int $year = null): string
    {
        $year ??= (int) now()->year;

        $prefijo = $event->order_prefix ?: 'SI';

        DB::table('order_number_sequences')->insertOrIgnore([
            'prefix' => $prefijo,
            'year' => $year,
            'last_number' => 0,
        ]);

        $fila = DB::table('order_number_sequences')
            ->where('prefix', $prefijo)
            ->where('year', $year)
            ->lockForUpdate()
            ->first();

        $siguiente = (int) $fila->last_number + 1;

        DB::table('order_number_sequences')
            ->where('id', $fila->id)
            ->update(['last_number' => $siguiente]);

        return sprintf('%s-%d-%04d', $prefijo, $year, $siguiente);
    }
}
