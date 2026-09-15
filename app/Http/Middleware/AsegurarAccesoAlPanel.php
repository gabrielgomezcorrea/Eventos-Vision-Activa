<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Saca del sistema a quien ya no debería estar adentro.
 *
 * El login rechaza a los usuarios desactivados o sin rol, pero una sesión
 * abierta antes de desactivarlos seguiría viva. Sin esto, desactivar a alguien
 * que se fue de la empresa no le cerraba la puerta hasta que expirara su sesión.
 */
class AsegurarAccesoAlPanel
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        if ($usuario !== null && ! $usuario->puedeEntrarAlPanel()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return to_route('login')->with('status', 'Tu cuenta no tiene acceso al sistema. Pide a Administración que la revise.');
        }

        return $next($request);
    }
}
