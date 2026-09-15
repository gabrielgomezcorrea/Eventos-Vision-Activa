<?php

use App\Http\Controllers\Acreditacion\AcreditacionController;
use App\Http\Controllers\Comprobantes\ComprobanteController;
use App\Http\Controllers\Configuracion\CuentaBancariaController;
use App\Http\Controllers\Configuracion\UsuarioController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DescargarComprobanteController;
use App\Http\Controllers\Eventos\AccesoController;
use App\Http\Controllers\Eventos\CuposYPreciosController;
use App\Http\Controllers\Eventos\EventoController;
use App\Http\Controllers\Eventos\FormularioPublicoController;
use App\Http\Controllers\Eventos\JornadaController;
use App\Http\Controllers\Eventos\TramoDescuentoController;
use App\Http\Controllers\Inscripciones\InscripcionController;
use App\Http\Controllers\Solicitudes\ExportarContactosController;
use App\Http\Controllers\Solicitudes\SolicitudController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', fn (Request $request) => to_route($request->user() ? 'dashboard' : 'login'))->name('home');

// Los comprobantes viven en disco privado. Esta ruta es la unica via de
// acceso: verifica el permiso y registra cada descarga o vista.
Route::get('/comprobantes/archivo/{proof}', DescargarComprobanteController::class)
    ->middleware(['auth'])
    ->name('comprobantes.descargar');

// Modulo de acreditacion. Interfaz propia fuera del panel: se usa de pie, con
// un telefono y con prisa. Comparte la sesion del panel para la autenticacion.
Route::middleware(['auth'])->prefix('acreditacion')->name('acreditacion.')->group(function (): void {
    Route::get('/', [AcreditacionController::class, 'inicio'])->name('inicio');
    Route::post('/resolver', [AcreditacionController::class, 'resolver'])->name('resolver');
    Route::post('/confirmar', [AcreditacionController::class, 'confirmar'])->name('confirmar');
    Route::get('/buscar', [AcreditacionController::class, 'buscar'])->name('buscar');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::get('eventos', [EventoController::class, 'index'])->name('eventos.index');
    Route::post('eventos', [EventoController::class, 'store'])->name('eventos.store');
    Route::get('eventos/{event}', [EventoController::class, 'show'])->name('eventos.show');
    Route::patch('eventos/{event}', [EventoController::class, 'update'])->name('eventos.update');
    Route::patch('eventos/{event}/estado', [EventoController::class, 'cambiarEstado'])->name('eventos.estado');
    Route::delete('eventos/{event}', [EventoController::class, 'destroy'])->name('eventos.destroy');

    Route::get('eventos/{event}/cupos', CuposYPreciosController::class)->name('eventos.cupos');
    Route::get('eventos/{event}/formulario', [FormularioPublicoController::class, 'edit'])->name('eventos.formulario');
    Route::put('eventos/{event}/formulario', [FormularioPublicoController::class, 'update'])->name('eventos.formulario.update');
    Route::post('eventos/{event}/programa', [FormularioPublicoController::class, 'subirPrograma'])->name('eventos.programa.store');
    Route::delete('eventos/{event}/programa/{attachment}', [FormularioPublicoController::class, 'quitarPrograma'])
        ->scopeBindings()
        ->name('eventos.programa.destroy');

    // Jornadas y accesos siempre dentro de su evento: una jornada ajena da 404.
    Route::scopeBindings()->group(function (): void {
        Route::post('eventos/{event}/jornadas', [JornadaController::class, 'store'])->name('eventos.jornadas.store');
        Route::patch('eventos/{event}/jornadas/{session}', [JornadaController::class, 'update'])->name('eventos.jornadas.update');
        Route::delete('eventos/{event}/jornadas/{session}', [JornadaController::class, 'destroy'])->name('eventos.jornadas.destroy');
        Route::post('eventos/{event}/jornadas/{session}/mover', [JornadaController::class, 'mover'])->name('eventos.jornadas.mover');

        Route::post('eventos/{event}/accesos', [AccesoController::class, 'store'])->name('eventos.accesos.store');
        Route::patch('eventos/{event}/accesos/{accessType}', [AccesoController::class, 'update'])->name('eventos.accesos.update');
        Route::delete('eventos/{event}/accesos/{accessType}', [AccesoController::class, 'destroy'])->name('eventos.accesos.destroy');
        Route::post('eventos/{event}/accesos/{accessType}/mover', [AccesoController::class, 'mover'])->name('eventos.accesos.mover');

        Route::post('eventos/{event}/descuentos', [TramoDescuentoController::class, 'store'])->name('eventos.descuentos.store');
        Route::patch('eventos/{event}/descuentos/{tier}', [TramoDescuentoController::class, 'update'])->name('eventos.descuentos.update');
        Route::delete('eventos/{event}/descuentos/{tier}', [TramoDescuentoController::class, 'destroy'])->name('eventos.descuentos.destroy');
    });

    Route::get('comprobantes', [ComprobanteController::class, 'index'])->name('comprobantes.index');
    Route::get('comprobantes/{payment}', [ComprobanteController::class, 'show'])->name('comprobantes.show');
    Route::post('comprobantes/{payment}/revisar', [ComprobanteController::class, 'revisar'])->name('comprobantes.revisar');

    Route::get('inscripciones', [InscripcionController::class, 'index'])->name('inscripciones.index');
    Route::get('inscripciones/{order}', [InscripcionController::class, 'show'])->name('inscripciones.show');
    Route::get('inscripciones/{order}/credenciales', [InscripcionController::class, 'credenciales'])->name('inscripciones.credenciales');
    Route::patch('inscripciones/{order}/notas', [InscripcionController::class, 'actualizarNotas'])->name('inscripciones.notas');
    Route::post('inscripciones/{order}/comprobante', [InscripcionController::class, 'cargarComprobante'])->name('inscripciones.comprobante');
    Route::post('inscripciones/{order}/factura', [InscripcionController::class, 'registrarFactura'])->name('inscripciones.factura');
    Route::post('inscripciones/{order}/cancelar', [InscripcionController::class, 'cancelar'])->name('inscripciones.cancelar');
    Route::post('inscripciones/{order}/reactivar', [InscripcionController::class, 'reactivar'])->name('inscripciones.reactivar');
    Route::post('inscripciones/{order}/participantes/{participant}/reemplazar', [InscripcionController::class, 'reemplazar'])
        ->scopeBindings()
        ->name('inscripciones.reemplazar');
    Route::patch('inscripciones/{order}/participantes/{participant}', [InscripcionController::class, 'corregirParticipante'])
        ->scopeBindings()
        ->name('inscripciones.participantes.corregir');

    Route::get('solicitudes', [SolicitudController::class, 'index'])->name('solicitudes.index');
    Route::get('solicitudes/exportar', ExportarContactosController::class)->name('solicitudes.exportar');
    Route::get('solicitudes/exportar/cantidad', [ExportarContactosController::class, 'cantidad'])->name('solicitudes.exportar.cantidad');
    Route::get('solicitudes/{programRequest}', [SolicitudController::class, 'show'])->name('solicitudes.show');
    Route::patch('solicitudes/{programRequest}/notas', [SolicitudController::class, 'actualizarNotas'])->name('solicitudes.notas');
    Route::post('solicitudes/{programRequest}/inscrita', [SolicitudController::class, 'alternarInscrita'])->name('solicitudes.inscrita');
    Route::post('solicitudes/{programRequest}/reenviar', [SolicitudController::class, 'reenviarPrograma'])->name('solicitudes.reenviar');
    Route::delete('solicitudes/{programRequest}', [SolicitudController::class, 'destroy'])->name('solicitudes.destroy');

    Route::get('settings/cuentas-bancarias', [CuentaBancariaController::class, 'index'])->name('cuentas-bancarias.index');
    Route::post('settings/cuentas-bancarias', [CuentaBancariaController::class, 'store'])->name('cuentas-bancarias.store');
    Route::get('settings/cuentas-bancarias/{bankAccount}', [CuentaBancariaController::class, 'show'])->name('cuentas-bancarias.show');
    Route::patch('settings/cuentas-bancarias/{bankAccount}', [CuentaBancariaController::class, 'update'])->name('cuentas-bancarias.update');
    Route::delete('settings/cuentas-bancarias/{bankAccount}', [CuentaBancariaController::class, 'destroy'])->name('cuentas-bancarias.destroy');

    Route::get('settings/usuarios', [UsuarioController::class, 'index'])->name('usuarios.index');
    Route::post('settings/usuarios', [UsuarioController::class, 'store'])->name('usuarios.store');
    Route::get('settings/usuarios/{user}', [UsuarioController::class, 'show'])->name('usuarios.show');
    Route::patch('settings/usuarios/{user}', [UsuarioController::class, 'update'])->name('usuarios.update');
    Route::delete('settings/usuarios/{user}', [UsuarioController::class, 'destroy'])->name('usuarios.destroy');
});

require __DIR__.'/settings.php';
