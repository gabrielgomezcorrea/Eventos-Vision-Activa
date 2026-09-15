<?php

namespace App\Http\Controllers\Eventos;

use App\Http\Controllers\Controller;
use App\Http\Requests\Eventos\AccesoRequest;
use App\Models\AccessType;
use App\Models\Event;
use App\Support\Reordenar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class AccesoController extends Controller
{
    public function store(AccesoRequest $request, Event $event): RedirectResponse
    {
        $datos = $request->validated();

        DB::transaction(function () use ($event, $datos): void {
            $acceso = $event->accessTypes()->create([
                ...Arr::except($datos, 'sessions'),
                'position' => ($event->accessTypes()->reorder()->max('position') ?? 0) + 1,
            ]);

            // Cada jornada marcada consume un cupo: el pivote trae 1 por defecto.
            $acceso->sessions()->sync($datos['sessions']);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Acceso agregado.']);

        return back();
    }

    public function update(AccesoRequest $request, Event $event, AccessType $accessType): RedirectResponse
    {
        $datos = $request->validated();

        DB::transaction(function () use ($accessType, $datos): void {
            $accessType->update(Arr::except($datos, 'sessions'));
            $accessType->sessions()->sync($datos['sessions']);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Acceso guardado.']);

        return back();
    }

    public function destroy(Event $event, AccessType $accessType): RedirectResponse
    {
        Gate::authorize('update', $event);

        $accessType->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => "Acceso «{$accessType->name}» eliminado."]);

        return back();
    }

    public function mover(Request $request, Event $event, AccessType $accessType): RedirectResponse
    {
        Gate::authorize('update', $event);

        $datos = $request->validate(['direccion' => ['required', 'in:arriba,abajo']]);

        Reordenar::mover($event->accessTypes()->orderBy('id')->get(), $accessType, $datos['direccion']);

        return back();
    }
}
