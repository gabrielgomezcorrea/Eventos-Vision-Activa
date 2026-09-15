<?php

namespace App\Http\Controllers\Eventos;

use App\Http\Controllers\Controller;
use App\Http\Requests\Eventos\JornadaRequest;
use App\Models\Event;
use App\Models\EventSession;
use App\Support\Reordenar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class JornadaController extends Controller
{
    public function store(JornadaRequest $request, Event $event): RedirectResponse
    {
        $event->sessions()->create([
            ...$request->validated(),
            // El orden se decide moviendo, no escribiendo un número.
            'position' => ($event->sessions()->reorder()->max('position') ?? 0) + 1,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Jornada agregada.']);

        return back();
    }

    public function update(JornadaRequest $request, Event $event, EventSession $session): RedirectResponse
    {
        $session->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Jornada guardada.']);

        return back();
    }

    public function destroy(Event $event, EventSession $session): RedirectResponse
    {
        Gate::authorize('update', $event);

        // Con cupos tomados arrastraría órdenes vivas.
        if ($session->reserved_seats > 0) {
            Inertia::flash('toast', ['type' => 'error', 'message' => "La jornada «{$session->name}» tiene cupos tomados y no se puede eliminar."]);

            return back();
        }

        $session->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => "Jornada «{$session->name}» eliminada."]);

        return back();
    }

    public function mover(Request $request, Event $event, EventSession $session): RedirectResponse
    {
        Gate::authorize('update', $event);

        $datos = $request->validate(['direccion' => ['required', 'in:arriba,abajo']]);

        Reordenar::mover($event->sessions()->orderBy('id')->get(), $session, $datos['direccion']);

        return back();
    }
}
