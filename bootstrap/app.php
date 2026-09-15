<?php

use App\Http\Middleware\AsegurarAccesoAlPanel;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\PermitirEmbebido;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Grupo público sin sesión, cookies ni CSRF: el formulario de
            // captación se embebe en sitios de terceros, donde las cookies
            // están bloqueadas por el navegador.
            Route::middleware([SubstituteBindings::class, PermitirEmbebido::class])
                ->group(base_path('routes/publico.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['sidebar_state']);

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            AsegurarAccesoAlPanel::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
