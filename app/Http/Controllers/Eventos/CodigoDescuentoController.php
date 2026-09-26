<?php

namespace App\Http\Controllers\Eventos;

use App\Enums\OrderStatus;
use App\Enums\TipoDescuento;
use App\Http\Controllers\Controller;
use App\Http\Requests\Eventos\ActualizarCodigoDescuentoRequest;
use App\Http\Requests\Eventos\CodigoDescuentoRequest;
use App\Models\DiscountCode;
use App\Models\Event;
use App\Models\Order;
use App\Models\Participant;
use App\Support\Auditor;
use App\Support\CodigoDeDescuento;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Códigos de descuento del evento. Nunca se borran: uno usado dejaría órdenes
 * apuntando a nada, así que se desactivan.
 */
class CodigoDescuentoController extends Controller
{
    public function index(Event $event): Response
    {
        Gate::authorize('viewAny', DiscountCode::class);

        $codigos = $event->discountCodes()->latest('id')->get();

        // Solo las inscripciones que hoy tienen los usos tomados: una vencida o
        // cancelada los devolvió, y un borrador todavía no los consume.
        $ordenes = Order::query()
            ->whereIn('discount_code_id', $codigos->pluck('id'))
            ->whereIn('status', [OrderStatus::Reservada, OrderStatus::Finalizada])
            ->with('participantesVigentes.establishment')
            ->orderBy('id')
            ->get()
            ->groupBy('discount_code_id');

        return Inertia::render('eventos/codigos', [
            'evento' => ['id' => $event->id, 'name' => $event->name],
            'codigos' => $codigos->map(fn (DiscountCode $codigo): array => [
                'personas' => $ordenes->get($codigo->id, collect())->flatMap(
                    fn (Order $orden) => $orden->participantesVigentes->map(fn (Participant $p): array => [
                        'id' => $p->id,
                        'nombre' => $p->nombre_completo,
                        'rut' => $p->rut,
                        'email' => $p->email,
                        'colegio' => $p->establishment?->name,
                        'folio' => $orden->number,
                        'orden_id' => $orden->id,
                    ])
                )->values()->all(),
                'id' => $codigo->id,
                'code' => $codigo->formateado(),
                'type' => $codigo->type->value,
                'value' => $codigo->value,
                'etiqueta' => $codigo->etiqueta(),
                'max_people' => $codigo->max_people,
                'used_people' => $codigo->used_people,
                'expires_on' => $codigo->expires_at->format('Y-m-d'),
                'vence' => $codigo->expires_at->format('d-m-Y'),
                'is_active' => $codigo->is_active,
                'estado' => self::estado($codigo),
            ])->values()->all(),
            // Cada carga trae uno nuevo: el modal abre con un código ya listo.
            'sugerido' => CodigoDeDescuento::formatear(CodigoDeDescuento::generar()),
            'hoy' => now()->format('Y-m-d'),
            'opciones' => ['tiposDescuento' => [
                'full' => 'Descuento completo',
                TipoDescuento::Porcentaje->value => 'Porcentaje',
                TipoDescuento::Monto->value => 'Monto fijo',
            ]],
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
}
