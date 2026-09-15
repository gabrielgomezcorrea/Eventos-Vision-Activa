<?php

namespace App\Actions;

use App\Exceptions\CuposInsuficientes;
use App\Models\EventSession;
use Illuminate\Support\Facades\DB;

/**
 * Toma y devuelve cupos de las jornadas.
 *
 * La garantía contra sobreventa no está en leer y después escribir, sino en un
 * UPDATE condicional: la condición de capacidad viaja dentro de la misma
 * sentencia que incrementa el contador, así que la base decide. Si otra
 * transacción ya ocupó la última vacante, el UPDATE afecta cero filas y aquí
 * se lanza la excepción.
 *
 * Leer disponibilidad y luego escribir dejaría una ventana entre ambas
 * operaciones en la que dos clientes pueden pasar la validación.
 *
 * Debe llamarse dentro de una transacción para que la devolución de cupos
 * ya tomados ocurra si una jornada posterior falla.
 */
class ReservarCupos
{
    /**
     * @param  array<int, int>  $consumo  [event_session_id => cantidad]
     *
     * @throws CuposInsuficientes
     */
    public function tomar(array $consumo): void
    {
        // Orden estable por id: si dos órdenes compiten por las mismas jornadas
        // en distinto orden, podrían bloquearse mutuamente.
        ksort($consumo);

        foreach ($consumo as $sessionId => $cantidad) {
            if ($cantidad < 1) {
                continue;
            }

            $filas = DB::table('event_sessions')
                ->where('id', $sessionId)
                ->where(function ($q) use ($cantidad): void {
                    $q->whereNull('capacity')
                        ->orWhereRaw('reserved_seats + ? <= capacity', [$cantidad]);
                })
                ->increment('reserved_seats', (int) $cantidad);

            if ($filas === 0) {
                throw CuposInsuficientes::para(
                    EventSession::find($sessionId),
                    $cantidad,
                );
            }
        }
    }

    /**
     * Devuelve cupos al expirar, cancelar o quitar participantes.
     *
     * @param  array<int, int>  $consumo  [event_session_id => cantidad]
     */
    public function devolver(array $consumo): void
    {
        foreach ($consumo as $sessionId => $cantidad) {
            if ($cantidad < 1) {
                continue;
            }

            // GREATEST evita que el contador quede negativo si alguna vez se
            // devuelve dos veces por un error de flujo.
            DB::update(
                'update event_sessions set reserved_seats = CASE WHEN reserved_seats < ? THEN 0 ELSE reserved_seats - ? END where id = ?',
                [(int) $cantidad, (int) $cantidad, $sessionId],
            );
        }
    }
}
