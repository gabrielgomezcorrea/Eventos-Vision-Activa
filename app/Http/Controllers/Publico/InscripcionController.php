<?php

namespace App\Http\Controllers\Publico;

use App\Actions\AplicarCodigoDeDescuento;
use App\Actions\ConfirmarConjunto;
use App\Actions\ConfirmarOrden;
use App\Actions\EmitirEnlaceDeAcceso;
use App\Actions\RegistrarComprobante;
use App\Enums\OrderKind;
use App\Exceptions\CodigoNoAplicable;
use App\Exceptions\ComprobanteNoAceptado;
use App\Exceptions\CuposInsuficientes;
use App\Exceptions\EnlaceNoUtilizable;
use App\Exceptions\OrdenNoConfirmable;
use App\Http\Controllers\Controller;
use App\Models\Establishment;
use App\Models\Event;
use App\Models\MagicLink;
use App\Models\Order;
use App\Models\OrderGroup;
use App\Models\PayerEntity;
use App\Rules\RutValido;
use App\Support\Auditor;
use App\Support\ReglasDeContacto;
use App\Support\Rut;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
    // ---------------------------------------------------------------- acceso

    public function inicio(Event $event): Renderable
    {
        throw_unless($event->admiteInscripciones(), EnlaceNoUtilizable::eventoNoDisponible($event));

        return view('publico.inscripcion.inicio', [
            'event' => $event,
            'enviado' => false,
            'valores' => [],
            'errores' => new MessageBag,
        ]);
    }

    public function solicitarEnlace(Request $request, Event $event, EmitirEnlaceDeAcceso $emitir): Renderable
    {
        abort_unless($event->admiteInscripciones(), 404);

        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email:rfc', 'max:255'],
        ], [], ['email' => 'correo']);

        if ($validator->fails()) {
            return view('publico.inscripcion.inicio', [
                'event' => $event,
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
            'enviado' => true,
            'valores' => [],
            'errores' => new MessageBag,
        ]);
    }

    /** Entra con el token del correo y abre o crea el borrador. */
    public function acceso(string $token): RedirectResponse|Renderable
    {
        $link = MagicLink::resolver($token);

        if (! $link) {
            throw EnlaceNoUtilizable::vencido(MagicLink::porToken($token)?->event);
        }

        $link->registrarUso();

        // Un enlace de conjunto ya tiene sus órdenes confirmadas: va directo
        // al estado, nunca crea un borrador.
        if ($link->order_group_id !== null) {
            return redirect()->route('inscripcion.estado', ['token' => $token]);
        }

        if (! $link->order) {
            $orden = Order::create([
                'event_id' => $link->event_id,
                'responsible_email' => $link->email,
                'responsible_name' => '',
                'kind' => OrderKind::Institucional,
            ]);

            $link->forceFill(['order_id' => $orden->getKey()])->save();

            Auditor::registrar($orden, 'orden.borrador_creado', actorLabel: $link->email);
        }

        return redirect()->route('inscripcion.responsable', ['token' => $token]);
    }

    // ----------------------------------------------------------------- pasos

    public function responsable(string $token): Renderable
    {
        [$orden] = $this->resolver($token);

        return $this->vistaPaso('responsable', $token, $orden, [
            'valores' => [
                'responsible_name' => $orden->responsible_name,
                'responsible_lastname' => $orden->responsible_lastname,
                ...ReglasDeContacto::separarCargo($orden->responsible_position, 'responsible_position'),
                'responsible_email' => $orden->responsible_email,
                'responsible_phone' => $orden->responsible_phone,
                'responsible_institution' => $orden->responsible_institution,
                'kind' => $orden->kind->value,
            ],
        ]);
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
            'responsible_phone' => ReglasDeContacto::telefono(),
            'responsible_institution' => ReglasDeContacto::establecimiento(! $particular),
            'kind' => ['required', Rule::in(array_map(fn (OrderKind $tipo) => $tipo->value, OrderKind::elegiblesPorElCliente()))],
        ], [], [
            'responsible_name' => 'nombre',
            'responsible_lastname' => 'apellidos',
            'responsible_position' => 'cargo',
            'responsible_position_otro' => 'cargo',
            'responsible_phone' => 'teléfono',
            'responsible_institution' => 'institución',
            'kind' => 'tipo de inscripción',
        ]);

        if ($validator->fails()) {
            return $this->vistaPaso('responsable', $token, $orden, [
                'valores' => $request->all(),
                'errores' => $validator->errors(),
            ]);
        }

        $orden->update(ReglasDeContacto::normalizar($validator->validated(), [
            'responsible_name' => 'nombre',
            'responsible_lastname' => 'nombre',
            'responsible_position' => 'cargo',
            'responsible_phone' => 'telefono',
            'responsible_institution' => 'nombre',
        ]));

        return redirect()->route('inscripcion.establecimientos', ['token' => $token]);
    }

    public function pagador(string $token): Renderable
    {
        [$orden] = $this->resolver($token);
        $pagador = $orden->payerEntity;

        return $this->vistaPaso('pagador', $token, $orden, [
            'valores' => [
                'name' => $pagador->name ?? ($orden->kind === OrderKind::Particular ? $orden->responsableNombreCompleto() : ''),
                'rut' => $pagador?->rut,
                'address' => $pagador?->address,
                'commune' => $pagador?->commune,
                'billing_email' => $pagador->billing_email ?? $orden->responsible_email,
                'phone' => $pagador->phone ?? $orden->responsible_phone,
            ],
        ]);
    }

    public function guardarPagador(Request $request, string $token): RedirectResponse|Renderable
    {
        [$orden] = $this->resolver($token);

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
            return $this->vistaPaso('pagador', $token, $orden, [
                'valores' => $request->all(),
                'errores' => $validator->errors(),
            ]);
        }

        $datos = ReglasDeContacto::normalizar($validator->validated(), [
            'name' => 'texto',
            'address' => 'texto',
            'commune' => 'comuna',
            'billing_email' => 'correo',
            'phone' => 'telefono',
        ]);
        $datos['rut'] = Rut::normalizar($datos['rut'] ?? null);

        if ($orden->payerEntity) {
            $orden->payerEntity->update($datos);
        } else {
            $orden->update(['payer_entity_id' => PayerEntity::create($datos)->getKey()]);
        }

        return redirect()->route('inscripcion.resumen', ['token' => $token]);
    }

    public function establecimientos(string $token): Renderable
    {
        [$orden] = $this->resolver($token);

        return $this->vistaPaso('establecimientos', $token, $orden, ['valores' => []]);
    }

    /**
     * Varios colegios se agregan aquí, uno por uno. Cada uno nace como su
     * propia inscripción al confirmar (App\Actions\ConfirmarConjunto): esto
     * solo junta los datos mientras la orden sigue siendo un borrador.
     */
    public function agregarEstablecimiento(Request $request, string $token): RedirectResponse|Renderable
    {
        [$orden] = $this->resolver($token);

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
            return $this->vistaPaso('establecimientos', $token, $orden, [
                'valores' => $request->all(),
                'errores' => $validator->errors(),
            ]);
        }

        $orden->establishments()->attach(Establishment::create(ReglasDeContacto::normalizar($validator->validated(), [
            'name' => 'nombre',
            'rbd' => 'texto',
            'address' => 'texto',
            'commune' => 'comuna',
        ])));

        return redirect()->route('inscripcion.establecimientos', ['token' => $token]);
    }

    public function quitarEstablecimiento(string $token, Establishment $establishment): RedirectResponse
    {
        [$orden] = $this->resolver($token);

        // Los participantes de ese establecimiento quedan sin asignar, no se borran.
        $orden->participants()->where('establishment_id', $establishment->getKey())
            ->update(['establishment_id' => null]);

        $orden->establishments()->detach($establishment);

        return redirect()->route('inscripcion.establecimientos', ['token' => $token]);
    }

    public function participantes(string $token): Renderable
    {
        [$orden] = $this->resolver($token);

        return $this->vistaPaso('participantes', $token, $orden, ['valores' => []]);
    }

    public function agregarParticipante(Request $request, string $token): RedirectResponse|Renderable
    {
        [$orden] = $this->resolver($token);

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
            return $this->vistaPaso('participantes', $token, $orden, [
                'valores' => $request->all(),
                'errores' => $validator->errors(),
            ]);
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

        $orden->participants()->create($datos);

        return redirect()->route('inscripcion.participantes', ['token' => $token]);
    }

    public function quitarParticipante(string $token, int $participante): RedirectResponse
    {
        [$orden] = $this->resolver($token);

        $orden->participants()->whereKey($participante)->delete();

        return redirect()->route('inscripcion.participantes', ['token' => $token]);
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

        return $this->agregarParticipante($request, $token);
    }

    public function resumen(string $token): Renderable
    {
        [$orden] = $this->resolver($token);

        return $this->vistaPaso('resumen', $token, $orden, ['valores' => []]);
    }

    public function aplicarCodigo(AplicarCodigoDeDescuento $aplicar, Request $request, string $token): RedirectResponse|Renderable
    {
        [$orden] = $this->resolver($token);

        try {
            $aplicar($orden, (string) $request->input('codigo'));
        } catch (CodigoNoAplicable $e) {
            return $this->vistaPaso('resumen', $token, $orden->load([
                'event', 'establishments', 'participants.accessType', 'participants.establishment', 'payerEntity',
            ]), [
                'valores' => [],
                'errorDeCodigo' => $e->getMessage(),
                'codigoEscrito' => (string) $request->input('codigo'),
            ]);
        }

        return redirect()->to(route('inscripcion.resumen', ['token' => $token]).'#codigo');
    }

    public function quitarCodigo(string $token): RedirectResponse
    {
        [$orden] = $this->resolver($token);

        $orden->forceFill(['discount_code_id' => null])->save();

        return redirect()->to(route('inscripcion.resumen', ['token' => $token]).'#codigo');
    }

    public function confirmar(ConfirmarOrden $confirmar, ConfirmarConjunto $confirmarConjunto, string $token): RedirectResponse|Renderable
    {
        [$orden] = $this->resolver($token);

        // Siempre queda en un conjunto, aunque termine con una sola orden: así
        // no hay dos caminos distintos en el código para "un colegio" y
        // "varios colegios".
        if ($orden->group_id === null) {
            $orden->forceFill([
                'group_id' => OrderGroup::paraResponsable($orden->event, $orden->responsible_email)->getKey(),
            ])->save();
        }

        try {
            $orden->establishments->count() > 1
                ? $confirmarConjunto($orden)
                : $confirmar($orden, actorLabel: $orden->responsible_email);
        } catch (OrdenNoConfirmable|CuposInsuficientes $e) {
            return $this->vistaPaso('resumen', $token, $orden->fresh()->load([
                'event', 'establishments', 'participants.accessType', 'participants.establishment', 'payerEntity',
            ]), [
                'valores' => [],
                'errorDeConfirmacion' => $e->getMessage(),
            ]);
        }

        return redirect()->route('inscripcion.estado', ['token' => $token]);
    }

    /**
     * Estado de una orden ya confirmada. A diferencia de los pasos, aquí no se
     * exige que la orden sea editable: el cliente debe poder consultarla siempre.
     */
    public function estado(Request $request, string $token): Renderable
    {
        $orden = $this->resolverParaConsulta($token);

        if ($orden->esBorrador()) {
            return $this->vistaPaso('resumen', $token, $orden, ['valores' => []]);
        }

        return $this->vistaEstado($token, $this->colegioElegido($orden, $request));
    }

    /**
     * Otro establecimiento: cada colegio va en su propia inscripción, con el
     * mismo correo, para poder ver después todo lo que inscribió esa persona.
     * No se le vuelve a pedir el correo: ya lo tenemos.
     */
    public function otroEstablecimiento(EmitirEnlaceDeAcceso $emitir, Request $request, string $token): RedirectResponse
    {
        $orden = $this->resolverParaConsulta($token);

        $emitir($orden->event, $orden->responsible_email, $request->ip(), nuevaInscripcion: true);

        // Vuelve al mismo colegio y al mismo bloque: sin el ancla, la página
        // recarga arriba del todo y el aviso de "te enviamos el correo" queda
        // fuera de pantalla. En pruebas de uso la persona apretó y creyó que
        // no había pasado nada.
        return redirect()->to(route('inscripcion.estado', [
            'token' => $token,
            'enviado' => 1,
            'colegio' => $request->integer('colegio') ?: null,
        ]).'#otro');
    }

    /** Carga del comprobante de transferencia por parte del cliente. */
    public function guardarComprobante(Request $request, RegistrarComprobante $registrar, string $token, ?Establishment $establishment = null): RedirectResponse|Renderable
    {
        $orden = $this->ordenDelConjunto($this->resolverParaConsulta($token), $establishment);

        $saldo = $orden->saldo();

        $validator = Validator::make($request->all(), [
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
            return $this->vistaEstado($token, $orden, $request->except('proof'), $validator->errors());
        }

        $datos = $validator->validated();
        $datos['payer_rut'] = Rut::normalizar($datos['payer_rut'] ?? null);
        $datos['reference'] = $orden->number;

        try {
            $registrar($orden, $datos, $request->file('proof'), actorLabel: $orden->responsible_email);
        } catch (ComprobanteNoAceptado $e) {
            return $this->vistaEstado($token, $orden->fresh(), [], null, $e->getMessage());
        }

        // Vuelve al colegio desde el que se subió: mandarlo al primero del
        // conjunto lo deja buscando en cuál de los siete estaba.
        return redirect()->route('inscripcion.estado', [
            'token' => $token, 'colegio' => $establishment?->id,
        ]);
    }

    // ------------------------------------------------------------- auxiliares

    /**
     * @param  array<string, mixed>  $valores  Solo se aplican a la tarjeta de
     *                                         $orden: cada colegio del conjunto
     *                                         se equivoca por su cuenta.
     */
    private function vistaEstado(
        string $token,
        Order $orden,
        array $valores = [],
        ?MessageBag $errores = null,
        ?string $errorDeComprobante = null,
    ): Renderable {
        $orden->load('payments.proofs', 'payments.reviews');

        // Con un solo colegio no hay conjunto que mostrar: se ve igual que hoy.
        $colegios = $orden->group
            ? $orden->group->orders()->with([
                'event', 'establishments', 'participants.accessType', 'participants.establishment',
                'payerEntity', 'payments.proofs', 'payments.reviews',
            ])->get()
            : collect([$orden]);

        return view('publico.inscripcion.estado', [
            'token' => $token,
            'orden' => $orden,
            'colegios' => $colegios,
            'event' => $orden->event,
            'valores' => $valores,
            'errores' => $errores ?? new MessageBag,
            'errorDeComprobante' => $errorDeComprobante,
        ]);
    }

    /**
     * Colegio que se está mirando en la pantalla de estado, elegido con
     * `?colegio=` desde el menú lateral. A diferencia de las acciones, aquí un
     * colegio ajeno no corta con 403: se vuelve al del token. Es un selector de
     * vista, no una acción, y dejar a alguien fuera de su propia pantalla por
     * un parámetro mal copiado es peor que ignorarlo. Lo que protege el dato
     * sigue siendo lo mismo: solo se muestran colegios del conjunto del token.
     */
    private function colegioElegido(Order $delToken, Request $request): Order
    {
        $elegido = $request->integer('colegio');

        if ($elegido === 0) {
            return $delToken;
        }

        $orden = $delToken->group?->load('orders.establishments')->orders
            ->first(fn (Order $o) => $o->establishments->contains('id', $elegido));

        return $orden?->load([
            'event', 'establishments', 'participants.accessType', 'participants.establishment',
            'payerEntity', 'payments.proofs', 'payments.reviews',
        ]) ?? $delToken;
    }

    /**
     * Orden objetivo dentro del conjunto: la del token si no se indica
     * colegio, o la del colegio indicado si pertenece al mismo conjunto que
     * el token. El colegio nunca autoriza por sí solo: siempre se verifica
     * contra el conjunto ya resuelto desde el token, nunca contra un id de
     * orden aceptado directamente de la URL.
     */
    private function ordenDelConjunto(Order $delToken, ?Establishment $establishment): Order
    {
        if ($establishment === null) {
            return $delToken;
        }

        $orden = $delToken->group?->load('orders.establishments')->orders
            ->first(fn (Order $o) => $o->establishments->contains($establishment));

        abort_if($orden === null, 403);

        return $orden->load([
            'event', 'establishments', 'participants.accessType', 'participants.establishment',
            'payerEntity', 'payments.proofs', 'payments.reviews',
        ]);
    }

    /** Resuelve el token sin exigir que la orden sea editable. */
    /**
     * Un enlace de orden resuelve directo; uno de conjunto no apunta a
     * ninguna en particular, así que se toma la más antigua como referencia
     * (vistaEstado igual junta a todas sus hermanas).
     */
    private function resolverParaConsulta(string $token): Order
    {
        $link = MagicLink::resolver($token);

        if ($link === null || ($link->order === null && $link->order_group_id === null)) {
            throw EnlaceNoUtilizable::vencido(MagicLink::porToken($token)?->event);
        }

        $orden = $link->order ?? $link->group->orders()->oldest('id')->firstOrFail();

        return $orden->load([
            'event', 'establishments', 'participants.accessType', 'participants.establishment',
            'payerEntity', 'payments.proofs', 'payments.reviews',
        ]);
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
            throw EnlaceNoUtilizable::vencido(MagicLink::porToken($token)?->event);
        }

        if (! $link->order->puedeEditarlaElCliente()) {
            throw EnlaceNoUtilizable::ordenNoEditable($token);
        }

        return [$link->order->load(['event', 'establishments', 'participants.accessType', 'participants.establishment', 'payerEntity']), $link];
    }

    /**
     * @param  'responsable'|'pagador'|'establecimientos'|'participantes'|'resumen'  $paso
     * @param  array<string, mixed>  $datos
     */
    private function vistaPaso(string $paso, string $token, Order $orden, array $datos): Renderable
    {
        return view("publico.inscripcion.{$paso}", array_merge([
            'token' => $token,
            'orden' => $orden,
            'event' => $orden->event,
            'paso' => $paso,
            'errores' => new MessageBag,
            'errorDeConfirmacion' => null,
        ], $datos));
    }
}
