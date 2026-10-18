<?php

namespace App\Http\Controllers\Eventos;

use App\Enums\TipoDescuento;
use App\Http\Controllers\Controller;
use App\Http\Requests\Eventos\ActualizarCodigoDescuentoRequest;
use App\Http\Requests\Eventos\CodigoDescuentoRequest;
use App\Models\DiscountCode;
use App\Models\Event;
use App\Models\Order;
use App\Support\Auditor;
use App\Support\CodigoDeDescuento;
use App\Support\Presentar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Códigos de descuento del evento. Uno que alguien ya usó no se borra (sus
 * inscripciones lo nombran): se desactiva. Uno que nadie usó sí se elimina.
 */
class CodigoDescuentoController extends Controller
{
    public function index(Event $event): Response
    {
        Gate::authorize('viewAny', DiscountCode::class);

        $codigos = $event->discountCodes()->latest('id')->get();

        return Inertia::render('eventos/codigos', [
            'evento' => ['id' => $event->id, 'name' => $event->name],
            'codigos' => $codigos->map(function (DiscountCode $codigo): array {
                // Una fila por persona (correo del solicitante), con todo lo que inscribió con el código.
                $personas = $codigo->usosPorPersona()->map(function ($ordenes, string $correo): array {
                    $primera = $ordenes->first();

                    return [
                        'correo' => $correo,
                        'nombre' => $primera->responsableNombreCompleto(),
                        'primer_uso' => $primera->confirmed_at ? Presentar::fechaYHora($primera->confirmed_at) : null,
                        'inscripciones' => $ordenes->map(fn (Order $orden): array => [
                            'orden_id' => $orden->id,
                            'folio' => $orden->number,
                            'colegio' => $orden->nombreDelColegio(),
                        ])->values()->all(),
                    ];
                })->values();

                return [
                    'personas' => $personas->all(),
                    'usado_por' => $personas->count(),
                    'id' => $codigo->id,
                    'code' => $codigo->formateado(),
                    'type' => $codigo->type->value,
                    'value' => $codigo->value,
                    'etiqueta' => $codigo->etiqueta(),
                    'max_people' => $codigo->max_people,
                    'expires_on' => $codigo->expires_at->format('Y-m-d'),
                    'vence' => $codigo->expires_at->format('d-m-Y'),
                    'is_active' => $codigo->is_active,
                    'uses_per_person' => $codigo->uses_per_person,
                    'usos_texto' => DiscountCode::USOS_POR_PERSONA[$codigo->uses_per_person] ?? '',
                    'estado' => self::estado($codigo),
                ];
            })->values()->all(),
            // Cada carga trae uno nuevo: el modal abre con un código ya listo.
            'sugerido' => CodigoDeDescuento::formatear(CodigoDeDescuento::generar()),
            'hoy' => now()->format('Y-m-d'),
            'opciones' => [
                'tiposDescuento' => [
                    'full' => 'Descuento completo',
                    TipoDescuento::Porcentaje->value => 'Porcentaje',
                    TipoDescuento::Monto->value => 'Monto fijo',
                ],
                'usosPorPersona' => DiscountCode::USOS_POR_PERSONA,
            ],
        ]);
    }

    /** @return array{value: string, label: string, color: string} */
    public static function estado(DiscountCode $codigo): array
    {
        $estado = $codigo->estado();

        return [
            'value' => $estado,
            'label' => match ($estado) {
                'active' => 'Vigente',
                'expired' => 'Vencido',
                'exhausted' => 'Sin cupos',
                default => 'Desactivado',
            },
            'color' => match ($estado) {
                'active' => 'success',
                'exhausted' => 'warning',
                default => 'gray',
            },
        ];
    }

    public function store(CodigoDescuentoRequest $request, Event $event): RedirectResponse
    {
        $codigo = $event->discountCodes()->create([
            'code' => $request->validated('code'),
            'type' => $request->validated('type'),
            'value' => $request->validated('value'),
            'max_people' => $request->validated('max_people'),
            'uses_per_person' => $request->validated('uses_per_person'),
            'expires_at' => Carbon::parse($request->validated('expires_on'))->endOfDay(),
            'created_by' => $request->user()->getKey(),
        ]);

        Auditor::registrar(
            sobre: $codigo,
            accion: 'codigo_descuento.creado',
            propiedades: ['evento' => $event->name, 'descuento' => $codigo->etiqueta(), 'maximo' => $codigo->max_people],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Código creado: '.$codigo->formateado()]);

        return back();
    }

    public function update(ActualizarCodigoDescuentoRequest $request, Event $event, DiscountCode $discountCode): RedirectResponse
    {
        $cambios = $request->validated();

        if (isset($cambios['expires_on'])) {
            $cambios['expires_at'] = Carbon::parse($cambios['expires_on'])->endOfDay();
            unset($cambios['expires_on']);
        }

        $discountCode->update($cambios);

        Auditor::registrar(
            sobre: $discountCode,
            accion: 'codigo_descuento.actualizado',
            propiedades: ['codigo' => $discountCode->formateado(), 'cambios' => $request->validated()],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Cambios guardados.']);

        return back();
    }

    public function destroy(Event $event, DiscountCode $discountCode): RedirectResponse
    {
        Gate::authorize('delete', DiscountCode::class);

        if ($discountCode->usosPorPersona()->isNotEmpty()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Este código ya lo usó alguien: no se puede eliminar. Desactívalo para que nadie más lo use.']);

            return back();
        }

        $codigo = $discountCode->formateado();
        // Un borrador que lo tenía puesto queda sin código (la columna se limpia sola).
        $discountCode->delete();

        Auditor::registrar(
            sobre: $event,
            accion: 'codigo_descuento.eliminado',
            propiedades: ['codigo' => $codigo],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => "Código {$codigo} eliminado."]);

        return back();
    }
}
