<?php

namespace App\Actions;

use App\Enums\EtapaDeSeguimiento;
use App\Models\ProgramRequest;
use App\Support\Texto;

/**
 * The Solicitudes list as it is on screen (stage, search and event) as rows to
 * download: "Pre inscrito" plus this file is what sales imports into Brevo.
 *
 * One row per email and event, the most recent request first.
 */
class ExportarEtapa
{
    public const COLUMNAS = ['correo', 'nombre', 'apellidos', 'telefono', 'cargo', 'institucion', 'evento', 'etapa', 'detalle', 'solicitud'];

    /**
     * @param  array{buscar?: string|null, evento?: int|null, seguimiento?: string|null}  $filtros
     * @return array<int, array<string, string|null>>
     */
    public function __invoke(EtapaDeSeguimiento $etapa, array $filtros): array
    {
        $solicitudes = ProgramRequest::query()
            ->delListado($filtros)
            ->enEtapa($etapa)
            ->deSeguimiento($etapa === EtapaDeSeguimiento::Solicito ? ($filtros['seguimiento'] ?? null) : null)
            ->with('event')
            ->withCount('notes')
            ->latest()
            ->get();

        $solicitudes = $solicitudes->unique(fn (ProgramRequest $s): string => mb_strtolower($s->email).'|'.$s->event_id)->values();
        $ordenes = ProgramRequest::ordenesDe($solicitudes);

        return $solicitudes->map(function (ProgramRequest $s) use ($etapa, $ordenes): array {
            $telefono = $s->datoOResponsable('phone', 'responsible_phone');

            return [
                'correo' => mb_strtolower(trim($s->email)),
                'nombre' => $s->datoOResponsable('first_name', 'responsible_name'),
                'apellidos' => $s->datoOResponsable('last_name', 'responsible_lastname'),
                // Stored phones may still carry "+" from before; Excel reads it as a formula.
                'telefono' => Texto::telefono($telefono) ?? ($telefono === null ? null : ltrim($telefono, '+')),
                'cargo' => $s->datoOResponsable('position', 'responsible_position'),
                'institucion' => $s->institucionParaMostrar(),
                'evento' => $s->event?->name,
                'etapa' => $etapa->label(),
                'detalle' => match ($etapa) {
                    EtapaDeSeguimiento::Solicito => $s->seguimiento(),
                    EtapaDeSeguimiento::PreInscrito => ProgramRequest::detalleDePreInscrito($ordenes[$s->id] ?? collect()),
                    default => null,
                },
                'solicitud' => $s->created_at->format('Y-m-d'),
            ];
        })->all();
    }
}
