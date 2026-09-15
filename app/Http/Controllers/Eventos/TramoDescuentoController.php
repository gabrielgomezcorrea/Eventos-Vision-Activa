<?php

namespace App\Http\Controllers\Eventos;

use App\Http\Controllers\Controller;
use App\Http\Requests\Eventos\TramoDescuentoRequest;
use App\Models\DiscountTier;
use App\Models\Event;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Tramos de descuento por cantidad. No se ordenan a mano: el orden lo da la
 * cantidad de participantes.
 */
class TramoDescuentoController extends Controller
{
    public function store(TramoDescuentoRequest $request, Event $event): RedirectResponse
    {
        $event->discountTiers()->create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Tramo de descuento agregado.']);

        return back();
    }

    public function update(TramoDescuentoRequest $request, Event $event, DiscountTier $tier): RedirectResponse
    {
        $tier->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Tramo actualizado.']);

        return back();
    }

    public function destroy(Event $event, DiscountTier $tier): RedirectResponse
    {
        Gate::authorize('update', $event);

        // Borrarlo no toca las órdenes ya confirmadas: su descuento quedó
        // congelado con su propio monto y su etiqueta.
        $tier->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Tramo eliminado.']);

        return back();
    }
}
