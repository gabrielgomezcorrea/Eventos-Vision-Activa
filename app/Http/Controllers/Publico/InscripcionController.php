<?php

namespace App\Http\Controllers\Publico;

use App\Actions\AplicarCodigoDeDescuento;
use App\Actions\CambiarCorreoDelResponsable;
use App\Actions\ConfirmarConjunto;
use App\Actions\ConfirmarOrden;
use App\Actions\EmitirEnlaceDeAcceso;
use App\Actions\IniciarInscripcionAdicional;
use App\Actions\ModificarInscripcion;
use App\Actions\RegistrarComprobante;
use App\Enums\OrderKind;
use App\Exceptions\CambioNoPermitido;
use App\Exceptions\CodigoNoAplicable;
use App\Exceptions\ComprobanteNoAceptado;
use App\Exceptions\CuposInsuficientes;
use App\Exceptions\EnlaceNoUtilizable;
use App\Exceptions\InscripcionAdicionalNoPermitida;
use App\Exceptions\OrdenNoConfirmable;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Publico\Concerns\ResuelveOrdenesDelPortal;
use App\Mail\AvisoComprobantePorRevisar;
use App\Mail\AvisoInscripcionConfirmada;
use App\Models\AccessType;
use App\Models\Establishment;
use App\Models\Event;
use App\Models\MagicLink;
use App\Models\Order;
use App\Models\OrderGroup;
use App\Models\PayerEntity;
use App\Models\ProgramRequest;
use App\Rules\RutValido;
use App\Support\Auditor;
use App\Support\DestinatariosDeAvisos;
use App\Support\ReglasDeContacto;
use App\Support\Rut;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\MessageBag;
use Illuminate\Validation\Rule;

/**
 * Flujo público de inscripción.
 *
 * Igual que la captación, corre sin sesión: el token del enlace viaja en la URL
 * e identifica la orden en cada paso. Por eso los errores de validación se
 * devuelven renderizando la vista con los valores del request.
 */
class InscripcionController extends Controller
{
    use ResuelveOrdenesDelPortal;

    // ---------------------------------------------------------------- acceso

    public function inicio(Event $event): Renderable
    {
        throw_unless($event->admiteInscripciones(), EnlaceNoUtilizable::eventoNoDisponible($event));

        return view('publico.inscripcion.inicio', [
            'event' => $event,
            'ubicacionBanner' => 'external',
            'enviado' => false,
            'valores' => [],
            'errores' => new MessageBag,
        ]);
    }

    public function solicitarEnlace(Request $request, Event $event, EmitirEnlaceDeAcceso $emitir): Renderable
    {
        abort_unless($event->admiteInscripciones(), 404);

        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email:rfc', 'regex:/@.+\..+/', 'max:255'],
        ], [
            'email.required' => 'Escribe tu correo.',
            'email.email' => 'Revisa tu correo. Debe verse así: nombre@correo.cl',
            'email.regex' => 'Revisa tu correo. Debe verse así: nombre@correo.cl',
            'email.max' => 'Ese correo es demasiado largo.',
        ], ['email' => 'correo']);

        if ($validator->fails()) {
            return view('publico.inscripcion.inicio', [
                'event' => $event,
                'ubicacionBanner' => 'external',
                'enviado' => false,
                'valores' => $request->only('email'),
                'errores' => $validator->errors(),
            ]);
        }

        $emitir($event, $request->string('email')->toString(), $request->ip());

        // Siempre se responde igual, se haya enviado o no: de lo contrario el
        // formulario revelaría si un correo tiene inscripción en el evento.
        return view('publico.inscripcion.inicio', [
            'event' => $event,
            'ubicacionBanner' => 'external',
            'enviado' => true,
            'valores' => $request->only('email'),
            'errores' => new MessageBag,
        ]);
    }

    /** Entra con el token del correo y abre o crea el borrador. */
    public function acceso(string $token, EmitirEnlaceDeAcceso $emitir): RedirectResponse|Renderable
    {
        $link = MagicLink::resolver($token);

        if (! $link) {
            throw EnlaceNoUtilizable::paraToken($token);
        }

        $link->registrarUso();

        if (($redireccion = $this->unirASolicitud($link, $token, $emitir)) !== null) {
            return $redireccion;
        }

        $link->refresh();

        // Un enlace de conjunto ya tiene sus órdenes confirmadas, y una orden
        // que ya no es borrador se retoma donde le toca: nunca crea un borrador.
        $existente = $link->order ?? $link->group?->orders()->oldest('id')->first();

        if ($existente !== null && ! $existente->esBorrador()) {
            return redirect()->route($this->pestanaPorDefecto($existente), ['token' => $token]);
        }

        if (! $link->order) {
            // Dos personas abriendo el mismo enlace a la vez no crean dos
            // borradores: se relee el enlace bajo candado y, si ya tiene
            // orden, se usa esa.
            DB::transaction(function () use ($link): void {
                $bloqueado = MagicLink::query()->whereKey($link->getKey())->lockForUpdate()->firstOrFail();

                if ($bloqueado->order_id !== null) {
                    return;
                }

                // Quien pidió el programa (solicitante) no es necesariamente
                // quien hace el trámite (responsable): el bloque Responsable
                // nace vacío y lo llena quien abre el enlace, aunque sea el
                // mismo. El solicitante queda unido por detrás. Los enlaces
                // viejos, sin solicitud, la buscan por correo.
                $solicitud = $link->programRequest
                    ?? ProgramRequest::query()
                        ->where('event_id', $link->event_id)
                        ->where('email', $link->email)
                        ->latest('id')
                        ->first();

                $orden = Order::create([
                    'event_id' => $link->event_id,
                    'program_request_id' => $solicitud?->getKey(),
                    'responsible_email' => '',
                    'responsible_name' => '',
                    'kind' => OrderKind::Institucional,
                ]);

                $link->forceFill(['order_id' => $orden->getKey()])->save();

                Auditor::registrar($orden, 'orden.borrador_creado', actorLabel: $link->email);
            });
        }

        return redirect()->route('inscripcion.portal.inscripcion', ['token' => $token]);
    }

    /**
     * Un enlace suelto (sin solicitud, orden ni conjunto) se vuelve el enlace
     * estable de la persona: el clic crea su solicitud directa, o se une a la
     * que ya tiene, para que todo enlace del mismo correo lleve a la misma
     * inscripción. Dos clics a la vez (la persona y el robot del correo) no
     * duplican nada gracias al candado por persona.
     */
    private function unirASolicitud(MagicLink $link, string $token, EmitirEnlaceDeAcceso $emitir): ?RedirectResponse
    {
        if ($link->program_request_id !== null || $link->order_id !== null || $link->order_group_id !== null) {
            return null;
        }

        try {
            return Cache::lock('inscripcion-directa:'.$link->event_id.':'.sha1($link->email), 10)
                ->block(5, fn (): ?RedirectResponse => DB::transaction(function () use ($link, $token, $emitir): ?RedirectResponse {
                    $bloqueado = MagicLink::query()->whereKey($link->getKey())->lockForUpdate()->firstOrFail();

                    if ($bloqueado->program_request_id !== null) {
                        return null;
                    }

                    $evento = $bloqueado->event;
                    $solicitud = ProgramRequest::query()
                        ->where('event_id', $bloqueado->event_id)
                        ->where('email', $bloqueado->email)
                        ->latest('id')
                        ->first();

                    if ($solicitud !== null) {
                        $estable = $emitir->enlaceGuardadoDe($evento, $bloqueado->email);

                        if ($estable !== null && $estable->isNot($bloqueado)) {
                            return redirect()->route('inscripcion.acceso', ['token' => $estable->token_encrypted]);
                        }
                    } else {
                        $solicitud = ProgramRequest::create([
                            'event_id' => $bloqueado->event_id,
                            'email' => $bloqueado->email,
                            'origin' => ProgramRequest::ORIGEN_DIRECTO,
                            'consented_at' => now(),
                            'ip_address' => request()->ip(),
                        ]);
                    }

                    $destino = [];
                    $colegios = $emitir->colegiosVigentesDe($evento, $bloqueado->email);

                    if ($colegios !== null) {
                        $destino['order_group_id'] = $colegios->first()->group_id;
                    } elseif (($viva = $emitir->ordenVigenteDe($evento, $bloqueado->email)) !== null) {
                        $destino['order_id'] = $viva->getKey();
                    }

                    $bloqueado->forceFill([
                        ...$destino,
                        'program_request_id' => $solicitud->getKey(),
                        'token_encrypted' => $token,
                    ])->save();

                    return null;
                }));
        } catch (LockTimeoutException) {
            throw EnlaceNoUtilizable::paraToken($token);
        }
    }

    // ----------------------------------------------------------------- pasos

    /** Enlace histórico del paso: ahora es un bloque de la pestaña Inscripción. */
    public function responsable(string $token): RedirectResponse
    {
        $this->resolverParaConsulta($token);

        return $this->aBloque($token, 'responsable', guardado: false);
    }

    public function guardarResponsable(Request $request, string $token): RedirectResponse|Renderable
    {
        [$orden] = $this->resolver($token);

        // A private enrollment may come from no institution; everything else is required.
        $particular = $request->input('kind') === OrderKind::Particular->value;

        $validator = Validator::make(ReglasDeContacto::limpiar($request->all()), [
            'responsible_name' => ReglasDeContacto::nombres(),
            'responsible_lastname' => ReglasDeContacto::apellidos(),
            'responsible_position' => ReglasDeContacto::cargo(),
            'responsible_position_otro' => ReglasDeContacto::cargoOtro('responsible_position'),
            'responsible_email' => ReglasDeContacto::correo(),
            'responsible_phone' => ReglasDeContacto::telefono(),
            'responsible_institution' => ReglasDeContacto::establecimiento(! $particular),
            'kind' => ['required', Rule::in(array_map(fn (OrderKind $tipo) => $tipo->value, OrderKind::elegiblesPorElCliente()))],
        ], [], [
            'responsible_name' => 'nombre',
            'responsible_lastname' => 'apellidos',
            'responsible_position' => 'cargo',
            'responsible_position_otro' => 'cargo',
            'responsible_email' => 'correo',
            'responsible_phone' => 'teléfono',
            'responsible_institution' => 'institución',
            'kind' => 'tipo de inscripción',
        ]);

        if ($validator->fails()) {
            return $this->vistaInscripcion($token, $orden, 'responsable', $request->all(), $validator->errors());
        }

        $datos = ReglasDeContacto::normalizar($validator->validated(), [
            'responsible_name' => 'nombre',
            'responsible_lastname' => 'nombre',
            'responsible_position' => 'cargo',
            'responsible_email' => 'correo',
            'responsible_phone' => 'telefono',
            'responsible_institution' => 'nombre',
        ]);

        // El correo no va en el `update`: cambia en todo el conjunto, con auditoría.
        $correo = $datos['responsible_email'];
        unset($datos['responsible_email']);

        DB::transaction(function () use ($orden, $datos, $correo): void {
            $orden->update($datos);
            app(CambiarCorreoDelResponsable::class)($orden, $correo, $orden->responsible_email ?: $correo);
        });

        return $this->aBloque($token, 'responsable');
    }

    /** Enlace histórico del paso: ahora es un bloque de la pestaña Inscripción. */
    public function pagador(string $token): RedirectResponse
    {
        $this->resolverParaConsulta($token);

        return $this->aBloque($token, 'pagador', guardado: false);
    }

    public function guardarPagador(Request $request, string $token, ModificarInscripcion $modificar): RedirectResponse|Renderable
    {
        [$orden] = $this->resolverParaCambio($token);

        // En una inscripción adicional la entidad pagadora es la misma de la
        // original: editarla cambiaría su factura.
        abort_if($orden->parent_order_id !== null, 403);

        // Everything the invoice needs from the payer.
        $validator = Validator::make(ReglasDeContacto::limpiar($request->all()), [
            'name' => ReglasDeContacto::texto('Fundación Educacional Los Andes'),
            'rut' => ['required', 'string', 'max:20', new RutValido],
            'address' => ReglasDeContacto::texto('Av. Grecia 1234'),
            'commune' => ReglasDeContacto::comuna(),
            'billing_email' => ReglasDeContacto::correo(),
            'phone' => ReglasDeContacto::telefono(),
        ], [], [
            'name' => 'nombre o razón social',
            'rut' => 'RUT',
            'address' => 'dirección',
            'commune' => 'comuna',
            'billing_email' => 'correo de facturación',
            'phone' => 'teléfono',
        ]);

        if ($validator->fails()) {
            return $this->vistaInscripcion($token, $orden, 'pagador', $request->all(), $validator->errors());
        }

        $datos = ReglasDeContacto::normalizar($validator->validated(), [
            'name' => 'texto',
            'address' => 'texto',
            'commune' => 'comuna',
            'billing_email' => 'correo',
            'phone' => 'telefono',
        ]);
        $datos['rut'] = Rut::normalizar($datos['rut'] ?? null);

        if (! $orden->esBorrador()) {
            try {
                $modificar->actualizarPagador($orden, $datos);
            } catch (CambioNoPermitido $e) {
                return $this->vistaInscripcion($token, $orden->fresh(), 'pagador', $request->all(), (new MessageBag)->add('cambio', $e->getMessage()));
            }

            return $this->aBloque($token, 'pagador');
        }

        if ($orden->payerEntity) {
            $orden->payerEntity->update($datos);
        } else {
            $orden->update(['payer_entity_id' => PayerEntity::create($datos)->getKey()]);
        }

        return $this->aBloque($token, 'pagador');
    }

    /** Enlace histórico del paso: ahora es un bloque de la pestaña Inscripción. */
    public function establecimientos(string $token): RedirectResponse
    {
        $this->resolverParaConsulta($token);

        return $this->aBloque($token, 'establecimientos', guardado: false);
    }

    /**
     * Varios colegios se agregan aquí, uno por uno. Cada uno nace como su
     * propia inscripción al confirmar (App\Actions\ConfirmarConjunto): esto
     * solo junta los datos mientras la orden sigue siendo un borrador.
     */
    public function agregarEstablecimiento(Request $request, string $token): RedirectResponse|Renderable
    {
        [$orden] = $this->resolver($token);

        // Una inscripción adicional es del mismo colegio que la original.
        abort_if($orden->parent_order_id !== null, 403);

        $validator = Validator::make(ReglasDeContacto::limpiar($request->all()), [
            'name' => ReglasDeContacto::establecimiento(),
            'rbd' => ReglasDeContacto::rbd(),
            'address' => ReglasDeContacto::texto('Av. Grecia 1234'),
            'commune' => ReglasDeContacto::comuna(),
        ], [
            'rbd.regex' => 'Escribe el RBD con números, como 12345-6.',
        ], [
            'name' => 'nombre del establecimiento',
            'address' => 'dirección',
            'commune' => 'comuna',
        ]);

        if ($validator->fails()) {
            return $this->vistaInscripcion($token, $orden, 'establecimientos', $request->all(), $validator->errors());
        }

        $orden->establishments()->attach(Establishment::create(ReglasDeContacto::normalizar($validator->validated(), [
            'name' => 'nombre',
            'rbd' => 'texto',
            'address' => 'texto',
            'commune' => 'comuna',
        ])));

        return $this->aBloque($token, 'establecimientos');
    }

    public function quitarEstablecimiento(string $token, Establishment $establishment): RedirectResponse
    {
        [$orden] = $this->resolver($token);

        abort_if($orden->parent_order_id !== null, 403);

        // Los participantes de ese establecimiento se van con él: dejarlos sin
        // colegio los mezclaría con otro al confirmar.
        $orden->participants()->where('establishment_id', $establishment->getKey())->delete();

        $orden->establishments()->detach($establishment);

        return $this->aBloque($token, 'establecimientos');
    }

    /** Enlace histórico del paso: ahora es un bloque de la pestaña Inscripción. */
    public function participantes(string $token): RedirectResponse
    {
        $this->resolverParaConsulta($token);

        return $this->aBloque($token, 'participantes', guardado: false);
    }

    public function agregarParticipante(Request $request, string $token, ModificarInscripcion $modificar): RedirectResponse|Renderable
    {
        [$orden] = $this->resolverParaCambio($token);

        $accesos = $orden->event->accessTypes()->where('is_active', true)->pluck('id')->all();
        $establecimientos = $orden->establishments->pluck('id')->all();
        // Con un solo colegio no se pregunta algo que ya se sabe.
        $requiereEstablecimiento = count($establecimientos) > 1;

        $validator = Validator::make(ReglasDeContacto::limpiar($request->all()), [
            'first_name' => ReglasDeContacto::nombres(),
            'last_name' => ReglasDeContacto::apellidos(),
            'rut' => ['required', 'string', 'max:20', new RutValido],
            'position' => ReglasDeContacto::cargo(true, $orden->event->cargosDeParticipante()),
            'position_otro' => ReglasDeContacto::cargoOtro('position'),
            'email' => ReglasDeContacto::correo(),
            'access_type_id' => ['required', Rule::in($accesos)],
            'establishment_id' => [$requiereEstablecimiento ? 'required' : 'nullable', Rule::in($establecimientos)],
        ], [
            'access_type_id.required' => 'Seleccione el tipo de acceso.',
            'access_type_id.in' => 'Seleccione un tipo de acceso disponible.',
            'establishment_id.required' => 'Seleccione el establecimiento del participante.',
            'establishment_id.in' => 'Seleccione un establecimiento de la orden.',
        ], [
            'first_name' => 'nombre',
            'last_name' => 'apellidos',
            'position' => 'cargo',
            'position_otro' => 'cargo',
            'rut' => 'RUT',
            'email' => 'correo',
        ]);

        if ($validator->fails()) {
            return $this->vistaInscripcion($token, $orden, 'participantes', $request->all(), $validator->errors());
        }

        $datos = ReglasDeContacto::normalizar($validator->validated(), [
            'first_name' => 'nombre',
            'last_name' => 'nombre',
            'position' => 'cargo',
            'email' => 'correo',
        ]);
        $datos['rut'] = Rut::normalizar($datos['rut'] ?? null);
        $datos['establishment_id'] = $requiereEstablecimiento
            ? (int) $validator->validated()['establishment_id']
            : ($establecimientos[0] ?? null);

        if ($orden->esBorrador()) {
            $orden->participants()->create($datos);

            return $this->aBloque($token, 'participantes');
        }

        // Ya confirmada: toma cupos, usos del código y recalcula el monto.
        try {
            $modificar->agregarParticipante($orden, $datos, AccessType::findOrFail($datos['access_type_id']));
        } catch (CambioNoPermitido|CuposInsuficientes $e) {
            return $this->vistaInscripcion($token, $orden->fresh(), 'participantes', $request->all(), (new MessageBag)->add('cambio', $e->getMessage()));
        }

        return $this->aBloque($token, 'participantes');
    }

    public function quitarParticipante(string $token, int $participante, ModificarInscripcion $modificar): RedirectResponse|Renderable
    {
        [$orden] = $this->resolverParaCambio($token);

        if ($orden->esBorrador()) {
            $orden->participants()->whereKey($participante)->delete();

            return $this->aBloque($token, 'participantes');
        }

        try {
            $modificar->quitarParticipante($orden, $orden->participants()->findOrFail($participante));
        } catch (CambioNoPermitido $e) {
            return $this->vistaInscripcion($token, $orden->fresh(), 'participantes', [], (new MessageBag)->add('cambio', $e->getMessage()));
        }

        return $this->aBloque($token, 'participantes');
    }

    /** Cambia el acceso de un participante en una inscripción ya confirmada. */
    public function cambiarAcceso(Request $request, string $token, int $participante, ModificarInscripcion $modificar): RedirectResponse|Renderable
    {
        [$orden] = $this->resolverParaCambio($token);

        $accesos = $orden->event->accessTypes()->where('is_active', true)->pluck('id')->all();

        $validator = Validator::make($request->all(), [
            'access_type_id' => ['required', Rule::in($accesos)],
        ], ['access_type_id.required' => 'Seleccione el tipo de acceso.', 'access_type_id.in' => 'Seleccione un tipo de acceso disponible.']);

        if ($validator->fails()) {
            return $this->vistaInscripcion($token, $orden, 'participantes', $request->all(), $validator->errors());
        }

        try {
            $modificar->cambiarAcceso(
                $orden,
                $orden->participants()->findOrFail($participante),
                AccessType::findOrFail($validator->validated()['access_type_id']),
            );
        } catch (CambioNoPermitido|CuposInsuficientes $e) {
            return $this->vistaInscripcion($token, $orden->fresh(), 'participantes', [], (new MessageBag)->add('cambio', $e->getMessage()));
        }

        return $this->aBloque($token, 'participantes');
    }

    /** Corrige nombre y contacto de un participante, sin tocar acceso ni monto. */
    public function corregirParticipante(Request $request, string $token, int $participante, ModificarInscripcion $modificar): RedirectResponse|Renderable
    {
        [$orden] = $this->resolverParaCambio($token, soloDatos: true);

        $validator = Validator::make(ReglasDeContacto::limpiar($request->all()), [
            'first_name' => ReglasDeContacto::nombres(),
            'last_name' => ReglasDeContacto::apellidos(),
            'position' => ReglasDeContacto::cargo(true, $orden->event->cargosDeParticipante()),
            'position_otro' => ReglasDeContacto::cargoOtro('position'),
            'email' => ReglasDeContacto::correo(),
            'phone' => ReglasDeContacto::telefono(false),
        ], [], [
            'first_name' => 'nombre', 'last_name' => 'apellidos', 'position' => 'cargo',
            'position_otro' => 'cargo', 'email' => 'correo', 'phone' => 'teléfono',
        ]);

        if ($validator->fails()) {
            return $this->vistaInscripcion($token, $orden, 'participantes', $request->all(), $validator->errors());
        }

        // Ni el RUT ni el acceso ni el establecimiento llegan hasta aquí: no
        // están en las reglas, y `validated()` solo devuelve lo que ellas listan.
        $datos = ReglasDeContacto::normalizar($validator->validated(), [
            'first_name' => 'nombre', 'last_name' => 'nombre', 'position' => 'cargo',
            'email' => 'correo', 'phone' => 'telefono',
        ]);
        unset($datos['position_otro']);

        try {
            $modificar->corregirParticipante($orden, $orden->participants()->findOrFail($participante), $datos);
        } catch (CambioNoPermitido $e) {
            return $this->vistaInscripcion($token, $orden->fresh(), 'participantes', [], (new MessageBag)->add('cambio', $e->getMessage()));
        }

        return $this->aBloque($token, 'participantes');
    }

    /**
     * Corrige nombre y contacto del responsable. El correo y el tipo quedan
     * fijos: tras confirmar, el correo lo corrige el panel.
     */
    public function corregirResponsable(Request $request, string $token, ModificarInscripcion $modificar): RedirectResponse|Renderable
    {
        [$orden] = $this->resolverParaCambio($token, soloDatos: true);

        $validator = Validator::make(ReglasDeContacto::limpiar($request->all()), [
            'responsible_name' => ReglasDeContacto::nombres(),
            'responsible_lastname' => ReglasDeContacto::apellidos(),
            'responsible_position' => ReglasDeContacto::cargo(),
            'responsible_position_otro' => ReglasDeContacto::cargoOtro('responsible_position'),
            'responsible_phone' => ReglasDeContacto::telefono(),
        ], [], [
            'responsible_name' => 'nombre', 'responsible_lastname' => 'apellidos',
            'responsible_position' => 'cargo', 'responsible_position_otro' => 'cargo',
            'responsible_phone' => 'teléfono',
        ]);

        if ($validator->fails()) {
            return $this->vistaInscripcion($token, $orden, 'responsable', $request->all(), $validator->errors());
        }

        $datos = ReglasDeContacto::normalizar($validator->validated(), [
            'responsible_name' => 'nombre', 'responsible_lastname' => 'nombre',
            'responsible_position' => 'cargo', 'responsible_phone' => 'telefono',
        ]);
        unset($datos['responsible_position_otro']);

        try {
            $modificar->corregirResponsable($orden, $datos);
        } catch (CambioNoPermitido $e) {
            return $this->vistaInscripcion($token, $orden->fresh(), 'responsable', [], (new MessageBag)->add('cambio', $e->getMessage()));
        }

        return $this->aBloque($token, 'responsable');
    }

    /** Copia los datos del responsable como participante. */
    public function responsableParticipa(Request $request, string $token): RedirectResponse|Renderable
    {
        [$orden] = $this->resolver($token);

        $request->merge([
            'first_name' => $orden->responsible_name,
            'last_name' => $orden->responsible_lastname,
            // The responsible chose from the buyer list: a position missing from
            // the participant list travels as "Otro" with its text.
            ...ReglasDeContacto::separarCargo($orden->responsible_position, 'position', $orden->event->cargosDeParticipante()),
            'email' => $orden->responsible_email,
            'rut' => $request->input('rut'),
            'establishment_id' => $request->input('establishment_id'),
        ]);

        return $this->agregarParticipante($request, $token, app(ModificarInscripcion::class));
    }

    /** Enlace histórico del resumen: vive en la pestaña Pago. */
    public function resumen(string $token): RedirectResponse
    {
        $this->resolverParaConsulta($token);

        return redirect()->route('inscripcion.portal.pago', ['token' => $token]);
    }

    public function aplicarCodigo(AplicarCodigoDeDescuento $aplicar, Request $request, string $token): RedirectResponse|Renderable
    {
        [$orden, $link] = $this->resolver($token);

        try {
            $aplicar($orden, (string) $request->input('codigo'), $link->email);
        } catch (CodigoNoAplicable $e) {
            return $this->vistaInscripcion($token, $orden, extra: [
                'errorDeCodigo' => $e->getMessage(),
                'codigoEscrito' => (string) $request->input('codigo'),
            ]);
        }

        return redirect()->route('inscripcion.portal.inscripcion', ['token' => $token]);
    }

    public function quitarCodigo(string $token): RedirectResponse
    {
        [$orden] = $this->resolver($token);

        $orden->forceFill(['discount_code_id' => null, 'discount_code_email' => null])->save();

        return redirect()->route('inscripcion.portal.inscripcion', ['token' => $token]);
    }

    public function confirmar(ConfirmarOrden $confirmar, ConfirmarConjunto $confirmarConjunto, string $token): RedirectResponse|Renderable
    {
        [$orden, $link] = $this->resolver($token);

        // Siempre queda en un conjunto, aunque termine con una sola orden: así
        // no hay dos caminos distintos en el código para "un colegio" y
        // "varios colegios". Se busca por el correo del enlace, no por el del
        // responsable: ese se edita y no debe meterla en el conjunto de otro.
        if ($orden->group_id === null) {
            $orden->forceFill([
                'group_id' => OrderGroup::paraResponsable($orden->event, $link->email)->getKey(),
            ])->save();
        }

        try {
            $confirmadas = $orden->establishments->count() > 1
                ? $confirmarConjunto($orden)
                : [$confirmar($orden, actorLabel: $orden->responsible_email)];
        } catch (OrdenNoConfirmable|CuposInsuficientes $e) {
            return $this->vistaResumen($token, $orden->fresh(), ['errorDeConfirmacion' => $e->getMessage()]);
        }

        // Un solo aviso por confirmación, aunque sean varios colegios.
        $confirmadas = collect($confirmadas)->each->load(['event', 'establishments', 'payerEntity']);

        foreach (DestinatariosDeAvisos::para('notify_confirmed_orders') as $destinatario) {
            Mail::to($destinatario)->queue(new AvisoInscripcionConfirmada($confirmadas));
        }

        return redirect()->route('inscripcion.portal.pago', ['token' => $token]);
    }

    /**
     * Enlace histórico (correos ya enviados): lleva a la pestaña del portal
     * que corresponde a la situación de la orden, conservando el colegio.
     */
    public function estado(Request $request, string $token): RedirectResponse
    {
        $orden = $this->colegioElegido($this->resolverParaConsulta($token), $request);

        return redirect()->to(route($this->pestanaPorDefecto($orden), array_filter([
            'token' => $token,
            'colegio' => $request->integer('colegio') ?: null,
            'inscripcion' => $request->query('inscripcion'),
            'enviado' => $request->boolean('enviado') ? 1 : null,
        ])));
    }

    /**
     * "Inscribir más participantes": crea una inscripción nueva del mismo
     * colegio, sin tocar la ya confirmada, y lleva al cliente a completarla.
     * Se edita con su propio enlace (ver `aSuPropioEnlace`).
     */
    public function adicional(Request $request, IniciarInscripcionAdicional $iniciar, string $token): RedirectResponse
    {
        $origen = $this->ordenDelConjunto($this->resolverParaConsulta($token), null);

        try {
            $nueva = $iniciar($origen);
        } catch (InscripcionAdicionalNoPermitida $e) {
            throw EnlaceNoUtilizable::inscripcionAdicionalNoDisponible($e->getMessage(), $token);
        }

        [, $propio] = MagicLink::emitir($nueva->event, $nueva->responsible_email, $nueva);

        return redirect()->to(route('inscripcion.portal.inscripcion', ['token' => $propio, 'inscripcion' => $nueva->public_id]).'#participantes');
    }

    /**
     * "Agregar otro establecimiento": borrador nuevo del mismo conjunto, sin
     * colegio ni entidad pagadora, que el cliente completa con su propio enlace.
     */
    public function otroEstablecimiento(IniciarInscripcionAdicional $iniciar, string $token): RedirectResponse
    {
        $origen = $this->ordenDelConjunto($this->resolverParaConsulta($token), null);

        try {
            $nueva = $iniciar->otroEstablecimiento($origen);
        } catch (InscripcionAdicionalNoPermitida $e) {
            throw EnlaceNoUtilizable::inscripcionAdicionalNoDisponible($e->getMessage(), $token);
        }

        [, $propio] = MagicLink::emitir($nueva->event, $nueva->responsible_email, $nueva);

        return redirect()->to(route('inscripcion.portal.inscripcion', ['token' => $propio, 'inscripcion' => $nueva->public_id]).'#establecimientos');
    }

    /** Carga del comprobante de transferencia por parte del cliente. */
    public function guardarComprobante(Request $request, RegistrarComprobante $registrar, string $token, ?Establishment $establishment = null): RedirectResponse|Renderable
    {
        $orden = $this->ordenDelConjunto($this->resolverParaConsulta($token), $establishment);

        $saldo = $orden->saldo();

        // Si en el intento anterior el archivo venía bien y falló otro campo,
        // se guardó aparte: no se le pide subirlo de nuevo.
        $guardado = $request->hasFile('proof') ? null : $this->comprobanteGuardado($request, $orden);
        $archivo = $request->file('proof') ?? $guardado;

        $validator = Validator::make(['proof' => $archivo] + $request->except('proof'), [
            'amount' => ['required', 'integer', 'min:1', 'max:'.max(1, $saldo)],
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'bank_name' => ['required', 'string', 'max:255'],
            'payer_name' => ['required', 'string', 'max:255'],
            'payer_rut' => ['required', 'string', 'max:20', new RutValido],
            'notes' => ['nullable', 'string', 'max:2000'],
            'billing_notes' => ['nullable', 'string', 'max:2000'],
            'proof' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
        ], [
            'proof.required' => 'Adjunta el comprobante de la transferencia.',
            'proof.mimes' => 'El comprobante debe ser un PDF o una imagen.',
            'proof.max' => 'El archivo no puede superar los 10 MB.',
            'paid_on.before_or_equal' => 'La fecha de transferencia no puede ser futura.',
            'amount.max' => 'El monto no puede superar el saldo pendiente de $'.number_format($saldo, 0, ',', '.').'.',
        ], [
            'amount' => 'monto',
            'paid_on' => 'fecha de transferencia',
            'bank_name' => 'banco de origen',
            'payer_name' => 'nombre del pagador',
            'payer_rut' => 'RUT del pagador',
            'billing_notes' => 'observaciones para facturación',
            'proof' => 'comprobante',
        ]);

        if ($validator->fails()) {
            $valores = $request->except(['proof', 'proof_guardado']);

            if ($archivo !== null && ! $validator->errors()->has('proof')) {
                $valores += $this->guardarComprobanteAparte($archivo, $orden, $guardado);
            }

            return view('publico.portal.pago', $this->datosDelPortal(
                $token, $orden, 'pago', $valores, $validator->errors(),
            ));
        }

        $datos = $validator->validated();
        unset($datos['proof']);
        $datos['payer_rut'] = Rut::normalizar($datos['payer_rut'] ?? null);
        $datos['reference'] = $orden->number;

        try {
            $pago = $registrar($orden, $datos, $archivo, actorLabel: $orden->responsible_email);
        } catch (ComprobanteNoAceptado $e) {
            return view('publico.portal.pago', $this->datosDelPortal(
                $token, $orden->fresh(), 'pago', errorDeComprobante: $e->getMessage(),
            ));
        }

        if ($guardado !== null) {
            @unlink($guardado->getPathname());
        }

        foreach (DestinatariosDeAvisos::para('notify_payment_proofs') as $destinatario) {
            Mail::to($destinatario)->queue(new AvisoComprobantePorRevisar($pago));
        }

        // Vuelve al colegio desde el que se subió: mandarlo al primero del
        // conjunto lo deja buscando en cuál de los siete estaba.
        // `comprobante=recibido` hace que Pago lo confirme en una alerta: sin
        // sesión no hay mensajes flash, y recargar en silencio no dice nada.
        return redirect()->route('inscripcion.portal.pago', [
            'token' => $token, 'comprobante' => 'recibido',
            ...($orden->group_id !== null && $orden->group->orders()->count() > 1 ? ['inscripcion' => $orden->public_id] : []),
        ]);
    }

    // ------------------------------------------------------------- auxiliares

    /**
     * Guarda el comprobante de un intento fallido para no pedirlo otra vez.
     * Sin sesión, lo que identifica el archivo viaja cifrado en un campo
     * oculto, atado a esta orden: no se puede apuntar a un archivo ajeno.
     *
     * @return array{proof_guardado: string, proof_nombre: string}
     */
    private function guardarComprobanteAparte(UploadedFile $archivo, Order $orden, ?UploadedFile $yaGuardado): array
    {
        $ruta = $yaGuardado?->getPathname()
            ?? Storage::disk(config('filesystems.private_disk'))->path(
                $archivo->store('comprobantes/pendientes', config('filesystems.private_disk'))
            );

        return [
            'proof_guardado' => Crypt::encryptString(json_encode([
                'ruta' => $ruta,
                'nombre' => $archivo->getClientOriginalName(),
                'tipo' => $archivo->getClientMimeType(),
                'orden' => $orden->getKey(),
            ])),
            'proof_nombre' => $archivo->getClientOriginalName(),
        ];
    }

    /** El comprobante guardado en el intento anterior, si sigue siendo de esta orden. */
    private function comprobanteGuardado(Request $request, Order $orden): ?UploadedFile
    {
        try {
            $datos = json_decode(Crypt::decryptString((string) $request->input('proof_guardado')), true);
        } catch (DecryptException) {
            return null;
        }

        if (($datos['orden'] ?? null) !== $orden->getKey() || ! is_file($datos['ruta'] ?? '')) {
            return null;
        }

        return new UploadedFile($datos['ruta'], $datos['nombre'], $datos['tipo'], null, true);
    }

    /**
     * Como `resolver`, pero también deja pasar a una inscripción confirmada
     * mientras su nivel de cambio lo permita (portal V2). El servidor lo
     * revalida otra vez dentro de la transacción que modifica.
     *
     * @return array{0: Order, 1: MagicLink}
     */
    private function resolverParaCambio(string $token, bool $soloDatos = false): array
    {
        $link = MagicLink::resolver($token);

        if ($link === null || $link->order === null) {
            throw EnlaceNoUtilizable::paraToken($token);
        }

        $nivel = $link->order->nivelDeCambioDelCliente();

        $permitido = $soloDatos ? $nivel->permiteCorregirDatos() : $nivel->permiteCambiarLaInscripcion();

        if (! $permitido) {
            throw EnlaceNoUtilizable::ordenNoEditable($token);
        }

        return [$link->order->load(['event', 'establishments', 'participants.accessType', 'participants.establishment', 'payerEntity']), $link];
    }

    /**
     * Resuelve el token y devuelve la orden. Un enlace solo da acceso a su
     * propia orden: nunca se acepta un id de orden desde la URL.
     *
     * @return array{0: Order, 1: MagicLink}
     */
    private function resolver(string $token): array
    {
        $link = MagicLink::resolver($token);

        if ($link === null || $link->order === null) {
            throw EnlaceNoUtilizable::paraToken($token);
        }

        if (! $link->order->puedeEditarlaElCliente()) {
            throw EnlaceNoUtilizable::ordenNoEditable($token);
        }

        return [$link->order->load(['event', 'establishments', 'participants.accessType', 'participants.establishment', 'payerEntity']), $link];
    }
}
