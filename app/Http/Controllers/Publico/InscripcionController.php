<?php

namespace App\Http\Controllers\Publico;

use App\Actions\ConfirmarOrden;
use App\Actions\EmitirEnlaceDeAcceso;
use App\Actions\RegistrarComprobante;
use App\Enums\OrderKind;
use App\Exceptions\ComprobanteNoAceptado;
use App\Exceptions\CuposInsuficientes;
use App\Exceptions\EnlaceNoUtilizable;
use App\Exceptions\OrdenNoConfirmable;
use App\Http\Controllers\Controller;
use App\Models\Establishment;
use App\Models\Event;
use App\Models\MagicLink;
use App\Models\Order;
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
        abort_unless($event->admiteInscripciones(), 404);

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

        $validator = Validator::make(ReglasDeContacto::limpiar($request->all()), [
            'responsible_name' => ReglasDeContacto::nombreCompleto(),
            'responsible_position' => ReglasDeContacto::cargo(false),
            'responsible_position_otro' => ReglasDeContacto::cargoOtro('responsible_position'),
            'responsible_phone' => ['nullable', 'string', 'max:50'],
            'responsible_institution' => ReglasDeContacto::establecimiento(false),
            'kind' => ['required', Rule::enum(OrderKind::class)],
        ], [], [
            'responsible_name' => 'nombre',
            'responsible_position' => 'cargo',
            'responsible_position_otro' => 'cargo',
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
            'responsible_position' => 'cargo',
            'responsible_institution' => 'nombre',
        ]));

        return redirect()->route('inscripcion.pagador', ['token' => $token]);
    }

    public function pagador(string $token): Renderable
    {
        [$orden] = $this->resolver($token);
        $pagador = $orden->payerEntity;

        return $this->vistaPaso('pagador', $token, $orden, [
            'valores' => [
                'name' => $pagador->name ?? ($orden->kind === OrderKind::Particular ? $orden->responsible_name : ''),
                'rut' => $pagador?->rut,
                'address' => $pagador?->address,
                'billing_email' => $pagador->billing_email ?? $orden->responsible_email,
                'phone' => $pagador->phone ?? $orden->responsible_phone,
            ],
        ]);
    }

    public function guardarPagador(Request $request, string $token): RedirectResponse|Renderable
    {
        [$orden] = $this->resolver($token);

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'rut' => ['nullable', 'string', 'max:20', new RutValido],
            'address' => ['nullable', 'string', 'max:255'],
            'billing_email' => ['nullable', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
        ], [], [
            'name' => 'nombre o razón social',
            'billing_email' => 'correo de facturación',
        ]);

        if ($validator->fails()) {
            return $this->vistaPaso('pagador', $token, $orden, [
                'valores' => $request->all(),
                'errores' => $validator->errors(),
            ]);
        }

        $datos = $validator->validated();
        $datos['rut'] = Rut::normalizar($datos['rut'] ?? null);

        if ($orden->payerEntity) {
            $orden->payerEntity->update($datos);
        } else {
            $orden->update(['payer_entity_id' => PayerEntity::create($datos)->getKey()]);
        }

        return redirect()->route('inscripcion.establecimientos', ['token' => $token]);
    }

    public function establecimientos(string $token): Renderable
    {
        [$orden] = $this->resolver($token);

        return $this->vistaPaso('establecimientos', $token, $orden, ['valores' => []]);
    }

    public function agregarEstablecimiento(Request $request, string $token): RedirectResponse|Renderable
    {
        [$orden] = $this->resolver($token);

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'rbd' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:255'],
            'commune' => ['nullable', 'string', 'max:255'],
        ], [], ['name' => 'nombre del establecimiento']);

        if ($validator->fails()) {
            return $this->vistaPaso('establecimientos', $token, $orden, [
                'valores' => $request->all(),
                'errores' => $validator->errors(),
            ]);
        }

        $orden->establishments()->attach(Establishment::create($validator->validated()));

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

        $validator = Validator::make(ReglasDeContacto::limpiar($request->all()), [
            'first_name' => ReglasDeContacto::nombres(),
            'last_name' => ReglasDeContacto::apellidos(),
            'rut' => ['nullable', 'string', 'max:20', new RutValido],
            'position' => ReglasDeContacto::cargo(),
            'position_otro' => ReglasDeContacto::cargoOtro('position'),
            'email' => ReglasDeContacto::correo(),
            'access_type_id' => ['required', Rule::in($accesos)],
            'establishment_id' => [
                $orden->kind->requiereEstablecimiento() && $establecimientos !== [] ? 'required' : 'nullable',
                Rule::in($establecimientos),
            ],
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

        $partes = preg_split('/\s+/', trim($orden->responsible_name), 2);

        $request->merge([
            'first_name' => $partes[0] ?? '',
            'last_name' => $partes[1] ?? null,
            ...ReglasDeContacto::separarCargo($orden->responsible_position),
            'email' => $orden->responsible_email,
        ]);

        return $this->agregarParticipante($request, $token);
    }

    public function resumen(string $token): Renderable
    {
        [$orden] = $this->resolver($token);

        return $this->vistaPaso('resumen', $token, $orden, ['valores' => []]);
    }

    public function confirmar(ConfirmarOrden $confirmar, string $token): RedirectResponse|Renderable
    {
        [$orden] = $this->resolver($token);

        try {
            $confirmar($orden, actorLabel: $orden->responsible_email);
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
    public function estado(string $token): Renderable
    {
        $orden = $this->resolverParaConsulta($token);

        if ($orden->esBorrador()) {
            return $this->vistaPaso('resumen', $token, $orden, ['valores' => []]);
        }

        return $this->vistaEstado($token, $orden);
    }

    /** Carga del comprobante de transferencia por parte del cliente. */
    public function guardarComprobante(Request $request, RegistrarComprobante $registrar, string $token): RedirectResponse|Renderable
    {
        $orden = $this->resolverParaConsulta($token);

        $validator = Validator::make($request->all(), [
            'amount' => ['required', 'integer', 'min:1'],
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'payer_name' => ['nullable', 'string', 'max:255'],
            'payer_rut' => ['nullable', 'string', 'max:20', new RutValido],
            'notes' => ['nullable', 'string', 'max:2000'],
            'proof' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
        ], [
            'proof.required' => 'Adjunta el comprobante de la transferencia.',
            'proof.mimes' => 'El comprobante debe ser un PDF o una imagen.',
            'proof.max' => 'El archivo no puede superar los 10 MB.',
            'paid_on.before_or_equal' => 'La fecha de transferencia no puede ser futura.',
        ], [
            'amount' => 'monto',
            'paid_on' => 'fecha de transferencia',
            'bank_name' => 'banco de origen',
            'payer_name' => 'nombre del pagador',
            'payer_rut' => 'RUT del pagador',
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

        return redirect()->route('inscripcion.estado', ['token' => $token]);
    }

    // ------------------------------------------------------------- auxiliares

    /** @param  array<string, mixed>  $valores */
    private function vistaEstado(
        string $token,
        Order $orden,
        array $valores = [],
        ?MessageBag $errores = null,
        ?string $errorDeComprobante = null,
    ): Renderable {
        return view('publico.inscripcion.estado', [
            'token' => $token,
            'orden' => $orden->load('payments.proofs', 'payments.reviews'),
            'event' => $orden->event,
            'valores' => $valores,
            'errores' => $errores ?? new MessageBag,
            'errorDeComprobante' => $errorDeComprobante,
        ]);
    }

    /** Resuelve el token sin exigir que la orden sea editable. */
    private function resolverParaConsulta(string $token): Order
    {
        $link = MagicLink::resolver($token);

        if ($link === null || $link->order === null) {
            throw EnlaceNoUtilizable::vencido(MagicLink::porToken($token)?->event);
        }

        return $link->order->load([
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
