<?php

namespace App\Http\Controllers\Eventos;

use App\Http\Controllers\Controller;
use App\Http\Requests\Eventos\InvitacionRequest;
use App\Mail\InvitacionAlEvento;
use App\Models\AccessType;
use App\Models\Event;
use App\Models\Invitation;
use App\Support\Auditor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Invitaciones personales del evento. Una usada no se reenvía: se crea otra.
 */
class InvitacionController extends Controller
{
    public function index(Event $event): Response
    {
        Gate::authorize('viewAny', Invitation::class);

        $event->load('accessTypes.sessions');

        return Inertia::render('eventos/invitaciones', [
            'evento' => ['id' => $event->id, 'name' => $event->name, 'starts_on' => $event->starts_on?->format('Y-m-d')],
            'invitaciones' => $event->invitations()->with('accessType')->latest('id')->get()->map(fn (Invitation $i): array => [
                'id' => $i->id,
                'email' => $i->email,
                'acceso' => $i->accessType->name,
                'vence' => $i->expires_at->format('d-m-Y'),
                'estado' => ['value' => $i->estado(), 'label' => $i->estadoEtiqueta(), 'color' => match ($i->estado()) {
                    'used' => 'success',
                    'pending' => 'info',
                    default => 'gray',
                }],
                'pendiente' => $i->estaPendiente(),
            ])->values()->all(),
            'accesos' => $event->accessTypes->where('is_active', true)->map(fn (AccessType $a): array => [
                'id' => $a->id,
                'name' => $a->name,
                // El acceso más justo manda: si una jornada está llena, no quedan.
                'disponibles' => $a->sessions->isEmpty() ? null : $a->sessions->map->cuposDisponibles()->filter(fn ($c) => $c !== null)->min(),
            ])->values()->all(),
            'hoy' => now()->format('Y-m-d'),
            // El día anterior al evento: pasado ese día ya no tiene sentido acreditarse como invitado.
            'sugerida' => $event->starts_on?->copy()->subDay()->format('Y-m-d'),
        ]);
    }

    public function store(InvitacionRequest $request, Event $event): RedirectResponse
    {
        $acceso = $event->accessTypes()->findOrFail($request->validated('access_type_id'));
        $vence = Carbon::parse($request->validated('expires_on'))->endOfDay();

        // Quien ya tiene una invitación pendiente no recibe otra: serían dos entradas gratis.
        $pendientes = $event->invitations()->get()->filter->estaPendiente()->pluck('email');
        $nuevos = array_values(array_diff($request->correos(), $pendientes->all()));
        $repetidos = array_values(array_intersect($request->correos(), $pendientes->all()));

        foreach ($nuevos as $correo) {
            [$invitacion, $token] = Invitation::emitir($event, $acceso, $correo, $vence, $request->user());

            Auditor::registrar(
                sobre: $invitacion,
                accion: 'invitacion.creada',
                propiedades: ['evento' => $event->name, 'correo' => $correo, 'acceso' => $acceso->name],
            );

            Mail::to($correo)->queue(new InvitacionAlEvento($invitacion, route('publico.invitacion', ['token' => $token])));
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => count($nuevos).' invitación(es) enviada(s).'
                .($repetidos === [] ? '' : ' Ya tenían una pendiente: '.implode(', ', $repetidos).'.'),
        ]);

        return back();
    }

    public function anular(Event $event, Invitation $invitation): RedirectResponse
    {
        Gate::authorize('update', Invitation::class);

        if (! $invitation->estaPendiente()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Esa invitación ya no está pendiente.']);

            return back();
        }

        $invitation->forceFill(['revoked_at' => now()])->save();

        Auditor::registrar(
            sobre: $invitation,
            accion: 'invitacion.anulada',
            propiedades: ['correo' => $invitation->email],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Invitación anulada.']);

        return back();
    }
}
