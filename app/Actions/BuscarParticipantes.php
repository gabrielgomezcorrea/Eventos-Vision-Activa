<?php

namespace App\Actions;

use App\Enums\OrderStatus;
use App\Models\Event;
use App\Models\Ticket;
use App\Support\Rut;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Búsqueda manual en la puerta: es el respaldo cuando el QR no se deja leer,
 * el participante llegó sin credencial o el teléfono se quedó sin batería.
 *
 * Busca por nombre, RUT, correo, código de credencial o número de inscripción,
 * sin obligar al operador a elegir en qué campo buscar.
 */
class BuscarParticipantes
{
    private const LIMITE = 25;

    /** @return Collection<int, Ticket> */
    public function __invoke(Event $evento, string $consulta): Collection
    {
        $consulta = trim($consulta);

        if (mb_strlen($consulta) < 2) {
            return collect();
        }

        $rut = Rut::normalizar($consulta);
        $codigo = Ticket::normalizarCodigo($consulta);
        $like = '%'.str_replace(' ', '%', $consulta).'%';

        return Ticket::query()
            ->where('event_id', $evento->getKey())
            ->vigentes()
            ->whereHas('order', fn (Builder $q) => $q->whereNotIn('status', [
                OrderStatus::Cancelada->value,
                OrderStatus::Vencida->value,
            ]))
            ->where(function (Builder $q) use ($rut, $codigo, $consulta, $like): void {
                $q->where('code', $codigo)
                    ->orWhereHas('order', fn (Builder $o) => $o->where('number', 'like', $like))
                    ->orWhereHas('participant', function (Builder $p) use ($rut, $consulta): void {
                        // Cada palabra debe aparecer en el nombre o el apellido.
                        // Así "ana perez" encuentra a Ana Pérez sin depender de
                        // concatenar columnas, que no es portable entre motores.
                        $palabras = preg_split('/\s+/', $consulta) ?: [];

                        $p->where(function (Builder $n) use ($palabras): void {
                            foreach ($palabras as $palabra) {
                                $n->where(fn (Builder $c) => $c
                                    ->where('first_name', 'like', '%'.$palabra.'%')
                                    ->orWhere('last_name', 'like', '%'.$palabra.'%'));
                            }
                        });

                        $p->orWhere('email', 'like', '%'.$consulta.'%');

                        // El RUT se guarda normalizado, así que se busca por la
                        // forma normalizada y no por lo que escribió el operador.
                        if ($rut !== null) {
                            $p->orWhere('rut', $rut);
                        }

                        if ($digitos = preg_replace('/[^0-9kK]/', '', $consulta)) {
                            $p->orWhere('rut', 'like', '%'.$digitos.'%');
                        }
                    });
            })
            ->with(['participant.accessType', 'participant.establishment', 'order', 'accreditation'])
            ->limit(self::LIMITE)
            ->get()
            ->sortBy(fn (Ticket $t) => $t->participant->nombre_completo)
            ->values();
    }
}
