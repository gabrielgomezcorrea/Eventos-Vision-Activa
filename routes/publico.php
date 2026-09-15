<?php

use App\Http\Controllers\Publico\InscripcionController;
use App\Http\Controllers\Publico\ProgramRequestController;
use App\Http\Controllers\Publico\TicketController;
use Illuminate\Support\Facades\Route;

/*
| Rutas públicas de captación.
|
| Este grupo no incluye sesión, cookies ni CSRF: el formulario va embebido en
| sitios de terceros donde las cookies están bloqueadas por el navegador. La
| protección contra abuso es rate limiting más honeypot, no un token de sesión.
*/

// Página de privacidad: la enlaza el pie de cada formulario público.
Route::view('/privacidad', 'publico.privacidad')->name('publico.privacidad');

Route::get('/f/{event:slug}', [ProgramRequestController::class, 'mostrar'])
    ->name('publico.programa');

Route::post('/f/{event:slug}', [ProgramRequestController::class, 'guardar'])
    ->middleware('throttle:10,1')
    ->name('publico.programa.guardar');

Route::get('/f/{event:slug}/embed', [ProgramRequestController::class, 'mostrarEmbebido'])
    ->name('publico.programa.embed');

Route::post('/f/{event:slug}/embed', [ProgramRequestController::class, 'guardarEmbebido'])
    ->middleware('throttle:10,1')
    ->name('publico.programa.embed.guardar');

/*
| Flujo de inscripción.
|
| El token del enlace viaja en la URL e identifica la orden en cada paso, de
| modo que el flujo tampoco necesita sesión. Un enlace solo da acceso a su
| propia orden: nunca se acepta un id de orden desde la URL.
*/

Route::get('/i/{event:slug}', [InscripcionController::class, 'inicio'])
    ->name('inscripcion.inicio');

Route::post('/i/{event:slug}', [InscripcionController::class, 'solicitarEnlace'])
    ->middleware('throttle:10,1')
    ->name('inscripcion.solicitar');

Route::get('/i/acceso/{token}', [InscripcionController::class, 'acceso'])
    ->name('inscripcion.acceso');

Route::prefix('/i/o/{token}')->name('inscripcion.')->group(function (): void {
    Route::get('/responsable', [InscripcionController::class, 'responsable'])->name('responsable');
    Route::post('/responsable', [InscripcionController::class, 'guardarResponsable'])->name('responsable.guardar');

    Route::get('/pagador', [InscripcionController::class, 'pagador'])->name('pagador');
    Route::post('/pagador', [InscripcionController::class, 'guardarPagador'])->name('pagador.guardar');

    Route::get('/establecimientos', [InscripcionController::class, 'establecimientos'])->name('establecimientos');
    Route::post('/establecimientos', [InscripcionController::class, 'agregarEstablecimiento'])->name('establecimientos.agregar');
    Route::delete('/establecimientos/{establishment}', [InscripcionController::class, 'quitarEstablecimiento'])->name('establecimientos.quitar');

    Route::get('/participantes', [InscripcionController::class, 'participantes'])->name('participantes');
    Route::post('/participantes', [InscripcionController::class, 'agregarParticipante'])->name('participantes.agregar');
    Route::post('/participantes/responsable', [InscripcionController::class, 'responsableParticipa'])->name('participantes.responsable');
    Route::delete('/participantes/{participante}', [InscripcionController::class, 'quitarParticipante'])->name('participantes.quitar');

    Route::get('/resumen', [InscripcionController::class, 'resumen'])->name('resumen');
    Route::post('/confirmar', [InscripcionController::class, 'confirmar'])->name('confirmar');

    Route::get('/estado', [InscripcionController::class, 'estado'])->name('estado');
    Route::get('/credenciales', [TicketController::class, 'deLaOrden'])->name('credenciales');
    Route::post('/comprobante', [InscripcionController::class, 'guardarComprobante'])
        ->middleware('throttle:20,1')
        ->name('comprobante');
});

// Credencial individual. Es la URL que lleva el QR: identificador opaco, sin
// datos personales en el propio codigo.
Route::get('/t/{token}', [TicketController::class, 'mostrar'])->name('ticket.mostrar');
