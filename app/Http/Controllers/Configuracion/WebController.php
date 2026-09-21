<?php

namespace App\Http\Controllers\Configuracion;

use App\Enums\Permiso;
use App\Http\Controllers\Controller;
use App\Support\Auditor;
use App\Support\WebsAutorizadas;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sites that may embed the public form. Global, only Administración: the same
 * permission as users, because it opens the system to a third-party site.
 */
class WebController extends Controller
{
    public function index(Request $request): Response
    {
        $this->autorizar($request);

        return Inertia::render('settings/webs/index', [
            'webs' => WebsAutorizadas::lista(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->autorizar($request);

        $validado = $request->validate([
            'dominio' => ['required', 'string', 'max:255', function (string $atributo, mixed $valor, Closure $falla): void {
                $dominio = WebsAutorizadas::normalizar(is_string($valor) ? $valor : null);

                if (is_string($valor) && preg_match('/[\s,;]/', trim($valor))) {
                    $falla('Agrega un website a la vez, como liderazgoescolar.cl.');
                } elseif ($dominio === null) {
                    $falla('Escribe un website, como liderazgoescolar.cl.');
                } elseif (in_array($dominio, WebsAutorizadas::lista(), true)) {
                    $falla("{$dominio} ya está autorizada.");
                }
            }],
        ], [], ['dominio' => 'website']);

        $dominio = (string) WebsAutorizadas::normalizar($validado['dominio']);
        WebsAutorizadas::guardar([...WebsAutorizadas::lista(), $dominio]);

        Auditor::registrar($request->user(), 'web.autorizada', propiedades: ['dominio' => $dominio]);
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$dominio} ya puede mostrar el formulario."]);

        return back();
    }

    public function destroy(Request $request): RedirectResponse
    {
        $this->autorizar($request);

        $dominio = (string) $request->input('dominio');
        WebsAutorizadas::guardar(array_values(array_diff(WebsAutorizadas::lista(), [$dominio])));

        Auditor::registrar($request->user(), 'web.quitada', propiedades: ['dominio' => $dominio]);
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$dominio} ya no puede mostrar el formulario."]);

        return back();
    }

    private function autorizar(Request $request): void
    {
        abort_unless($request->user()?->can(Permiso::GestionarUsuarios->value), 403);
    }
}
