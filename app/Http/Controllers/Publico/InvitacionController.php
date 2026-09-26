<?php

namespace App\Http\Controllers\Publico;

use App\Actions\AceptarInvitacion;
use App\Exceptions\CuposInsuficientes;
use App\Exceptions\EnlaceNoUtilizable;
use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Rules\RutValido;
use App\Support\ReglasDeContacto;
use App\Support\Rut;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\MessageBag;

/**
 * Inscripción de una persona invitada. Sin sesión: el token del enlace identifica
 * la invitación, y de ella salen el evento, el correo y el acceso. Nada de eso
 * viaja en la URL ni en el formulario.
 */
class InvitacionController extends Controller
{
    public function mostrar(string $token): Renderable
    {
        $invitacion = $this->resolver($token);

        return $this->vista($invitacion, ['valores' => [], 'errores' => new MessageBag]);
    }

    public function guardar(AceptarInvitacion $aceptar, Request $request, string $token): Renderable
    {
        $invitacion = $this->resolver($token);
        $evento = $invitacion->event;

        $validator = Validator::make(ReglasDeContacto::limpiar($request->all()), [
            'first_name' => ReglasDeContacto::nombres(),
            'last_name' => ReglasDeContacto::apellidos(),
            'rut' => ['required', 'string', 'max:20', new RutValido],
            'phone' => ReglasDeContacto::telefono(false),
            'position' => ReglasDeContacto::cargo(true, $evento->cargosDeParticipante()),
            'position_otro' => ReglasDeContacto::cargoOtro('position'),
            'establecimiento' => ReglasDeContacto::establecimiento(false),
        ], [], [
            'first_name' => 'nombre',
            'last_name' => 'apellidos',
            'rut' => 'RUT',
            'phone' => 'teléfono',
            'position' => 'cargo',
            'position_otro' => 'cargo',
            'establecimiento' => 'establecimiento',
        ]);

        if ($validator->fails()) {
            return $this->vista($invitacion, ['valores' => $request->all(), 'errores' => $validator->errors()]);
        }

        $datos = ReglasDeContacto::normalizar($validator->validated(), [
            'first_name' => 'nombre',
            'last_name' => 'nombre',
            'position' => 'cargo',
            'phone' => 'telefono',
            'establecimiento' => 'nombre',
        ]);
        $datos['rut'] = Rut::normalizar($datos['rut'] ?? null);

        try {
            $aceptar($invitacion, $datos);
        } catch (CuposInsuficientes) {
            return $this->vista($invitacion, [
                'valores' => $request->all(),
                'errores' => new MessageBag,
                'errorDeCupo' => 'Ya no quedan cupos para este evento. Avisa a quien te invitó.',
            ]);
        }

        return view('publico.invitacion-lista', ['event' => $evento, 'errores' => new MessageBag, 'email' => $invitacion->email]);
    }

    /** @throws EnlaceNoUtilizable */
    private function resolver(string $token): Invitation
    {
        $invitacion = Invitation::buscar($token);

        // Inexistente, usada, vencida y anulada responden igual: quien prueba
        // enlaces al azar no aprende nada.
        if ($invitacion === null || ! $invitacion->estaPendiente()) {
            throw EnlaceNoUtilizable::invitacionNoDisponible($invitacion?->event);
        }

        throw_unless($invitacion->event->admiteInscripciones(), EnlaceNoUtilizable::eventoNoDisponible($invitacion->event));

        return $invitacion->load('event');
    }

    /** @param  array<string, mixed>  $datos */
    private function vista(Invitation $invitacion, array $datos): Renderable
    {
        return view('publico.invitacion', array_merge([
            'token' => request()->route('token'),
            'event' => $invitacion->event,
            'invitacion' => $invitacion,
            'errorDeCupo' => null,
        ], $datos));
    }
}
