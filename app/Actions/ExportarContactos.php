<?php

namespace App\Actions;

use App\Enums\ParticipantStatus;
use App\Models\Order;
use App\Models\Participant;
use App\Models\ProgramRequest;
use App\Support\Forms\ProgramFormField;
use App\Support\Texto;
use Illuminate\Database\Eloquent\Builder;

/**
 * Arma la lista de contactos que el jefe limpia e importa en Brevo o el CRM.
 *
 * Una persona sale una sola vez, por correo. Si aparece en varias puertas, gana
 * el dato más confirmado: participante, luego responsable, luego solicitud.
 */
class ExportarContactos
{
    public const TIPOS = [
        'participante' => 'Participante',
        'responsable' => 'Responsable',
        'solicitud' => 'Solicitud',
    ];

    public const COLUMNAS = ['correo', 'nombre', 'apellidos', 'telefono', 'cargo', 'establecimiento', 'evento', 'tipo'];

    /**
     * @param  array{evento?: int|null, cargo?: string|null, tipos?: array<int, string>, pago?: string|null, marketing?: bool}  $filtros
     * @return array<int, array<string, string|null>>
     */
    public function __invoke(array $filtros): array
    {
        $tipos = $filtros['tipos'] ?? array_keys(self::TIPOS);
        $filas = [];

        if (in_array('participante', $tipos, true)) {
            $filas = [...$filas, ...$this->participantes($filtros)];
        }

        if (in_array('responsable', $tipos, true)) {
            $filas = [...$filas, ...$this->responsables($filtros)];
        }

        // Una solicitud no tiene pago: si se filtra por estado de pago, no aplica.
        if (in_array('solicitud', $tipos, true) && empty($filtros['pago'])) {
            $filas = [...$filas, ...$this->solicitudes($filtros)];
        }

        // One row per email. The first row wins, but its empty fields are filled
        // from the others: a participant has no phone, while the same person's
        // program request does, and the phone is what sales follows up with.
        $filas = collect($filas)
            ->filter(fn (array $fila): bool => filled($fila['correo']))
            ->groupBy('correo')
            ->map(function ($mismas): array {
                $unida = $mismas->shift();

                foreach ($mismas as $fila) {
                    foreach ($unida as $clave => $valor) {
                        $unida[$clave] = filled($valor) ? $valor : $fila[$clave];
                    }
                }

                return $unida;
            });

        if ($filtros['marketing'] ?? false) {
            // El consentimiento se da en el formulario público. Vale para la
            // persona, no para una sola solicitud: la casilla dice "próximos eventos".
            $conConsentimiento = ProgramRequest::query()
                ->whereNotNull('marketing_consented_at')
                ->pluck('email')
                ->map(fn (string $correo): string => mb_strtolower($correo))
                ->flip();

            $filas = $filas->filter(fn (array $fila): bool => $conConsentimiento->has((string) $fila['correo']));
        }

        return $filas->values()->all();
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return array<int, array<string, string|null>>
     */
    private function participantes(array $filtros): array
    {
        return Participant::query()
            ->with(['order.event', 'establishment'])
            ->where('status', '!=', ParticipantStatus::Reemplazado)
            ->whereHas('order', fn (Builder $q) => $this->filtrarOrden($q, $filtros))
            ->tap(fn (Builder $q) => $this->filtrarCargo($q, 'position', $filtros['cargo'] ?? null))
            ->latest()
            ->get()
            ->map(fn (Participant $p): array => $this->fila(
                $p->email, $p->first_name, $p->last_name, $p->phone, $p->position,
                $p->establishment?->name, $p->order->event->name, 'participante',
            ))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return array<int, array<string, string|null>>
     */
    private function responsables(array $filtros): array
    {
        return Order::query()
            ->with('event')
            ->where('responsible_name', '!=', '')
            ->tap(fn (Builder $q) => $this->filtrarOrden($q, $filtros))
            ->tap(fn (Builder $q) => $this->filtrarCargo($q, 'responsible_position', $filtros['cargo'] ?? null))
            ->latest()
            ->get()
            ->map(fn (Order $o): array => $this->fila(
                $o->responsible_email, $o->responsible_name, $o->responsible_lastname, $o->responsible_phone,
                $o->responsible_position, $o->responsible_institution, $o->event->name, 'responsable',
            ))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return array<int, array<string, string|null>>
     */
    private function solicitudes(array $filtros): array
    {
        return ProgramRequest::query()
            ->with('event')
            ->when($filtros['evento'] ?? null, fn (Builder $q, int $evento) => $q->where('event_id', $evento))
            ->tap(fn (Builder $q) => $this->filtrarCargo($q, 'position', $filtros['cargo'] ?? null))
            ->latest()
            ->get()
            ->map(fn (ProgramRequest $s): array => $this->fila(
                $s->email, $s->first_name, $s->last_name, $s->phone, $s->position,
                $s->institution, $s->event?->name, 'solicitud',
            ))
            ->all();
    }

    /**
     * @param  Builder<Order>  $consulta
     * @param  array<string, mixed>  $filtros
     */
    private function filtrarOrden(Builder $consulta, array $filtros): void
    {
        $consulta
            ->when($filtros['evento'] ?? null, fn (Builder $q, int $evento) => $q->where('event_id', $evento))
            ->when($filtros['pago'] ?? null, fn (Builder $q, string $pago) => $q->where('payment_status', $pago));
    }

    /**
     * "Otro" reúne los cargos escritos a mano, que son los que no están en la lista.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $consulta
     */
    private function filtrarCargo(Builder $consulta, string $columna, ?string $cargo): void
    {
        if ($cargo === null || $cargo === '') {
            return;
        }

        if ($cargo === ProgramFormField::CARGO_OTRO) {
            $consulta->whereNotNull($columna)->whereNotIn($columna, ProgramFormField::todosLosCargos());

            return;
        }

        $consulta->where($columna, $cargo);
    }

    /** @return array<string, string|null> */
    private function fila(?string $correo, ?string $nombre, ?string $apellidos, ?string $telefono, ?string $cargo, ?string $establecimiento, ?string $evento, string $tipo): array
    {
        return [
            'correo' => $correo === null ? null : mb_strtolower(trim($correo)),
            'nombre' => $nombre,
            'apellidos' => $apellidos,
            // Stored phones may still carry "+" from before; Excel reads it as a formula.
            'telefono' => Texto::telefono($telefono) ?? ($telefono === null ? null : ltrim($telefono, '+')),
            'cargo' => $cargo,
            'establecimiento' => $establecimiento,
            'evento' => $evento,
            'tipo' => self::TIPOS[$tipo],
        ];
    }
}
