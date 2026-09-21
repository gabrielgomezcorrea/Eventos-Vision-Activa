<?php

namespace App\Http\Controllers\Eventos;

use App\Enums\ModoConteoDescuento;
use App\Enums\TipoDescuento;
use App\Http\Controllers\Controller;
use App\Models\AccessType;
use App\Models\DiscountTier;
use App\Models\Event;
use App\Models\EventSession;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Las jornadas y los accesos que se venden, en su propia pantalla.
 *
 * Es un trabajo de sentarse una vez a configurar el evento, y las dos listas se
 * arman juntas: un acceso no existe sin jornadas que incluir. Por eso van una
 * debajo de la otra y no en pestañas.
 */
class CuposYPreciosController extends Controller
{
    public function __invoke(Event $event): Response
    {
        Gate::authorize('update', $event);

        $event->load(['sessions', 'accessTypes.sessions', 'discountTiers']);

        return Inertia::render('eventos/cupos', [
            'evento' => [
                'id' => $event->id,
                'name' => $event->name,
                'usa_pulseras' => $event->usaPulseras(),
                'discount_counting_mode' => $event->discount_counting_mode->value,
            ],
            'jornadas' => $event->sessions->map(fn (EventSession $jornada): array => [
                'id' => $jornada->id,
                'name' => $jornada->name,
                'starts_at' => $jornada->starts_at?->format('Y-m-d\TH:i'),
                'ends_at' => $jornada->ends_at?->format('Y-m-d\TH:i'),
                'inicio' => $jornada->starts_at?->format('d-m-Y H:i'),
                'location' => $jornada->location,
                'capacity' => $jornada->capacity,
                'reserved_seats' => $jornada->reserved_seats,
                'disponibles' => $jornada->cuposDisponibles(),
            ])->values()->all(),
            'accesos' => $event->accessTypes->map(fn (AccessType $acceso): array => [
                'id' => $acceso->id,
                'name' => $acceso->name,
                'price' => $acceso->price,
                'early_price' => $acceso->early_price,
                'early_until' => $acceso->early_until?->format('Y-m-d'),
                'description' => $acceso->description,
                'is_active' => $acceso->is_active,
                'wristband_label' => $acceso->wristband_label,
                'wristband_color' => $acceso->wristband_color,
                'sessions' => $acceso->sessions->pluck('id')->all(),
                'jornadas' => $acceso->sessions->sortBy('position')->pluck('name')->values()->all(),
            ])->values()->all(),
            'descuentos' => $event->discountTiers->map(fn (DiscountTier $tramo): array => [
                'id' => $tramo->id,
                'min_participants' => $tramo->min_participants,
                'type' => $tramo->type->value,
                'value' => $tramo->value,
                'etiqueta' => $tramo->etiqueta(),
            ])->values()->all(),
            'opciones' => [
                'tiposDescuento' => TipoDescuento::options(),
                'modosConteoDescuento' => ModoConteoDescuento::options(),
            ],
        ]);
    }
}
