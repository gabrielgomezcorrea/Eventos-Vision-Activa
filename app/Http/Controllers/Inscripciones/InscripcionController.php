<?php

namespace App\Http\Controllers\Inscripciones;

use App\Actions\CancelarOrden;
use App\Actions\ReactivarReserva;
use App\Actions\ReemplazarParticipante;
use App\Actions\RegistrarComprobante;
use App\Enums\InvoiceDocumentType;
use App\Enums\OrderStatus;
use App\Enums\ParticipantStatus;
use App\Enums\PaymentStatus;
use App\Enums\Permiso;
use App\Exceptions\CancelacionNoPermitida;
use App\Exceptions\ComprobanteNoAceptado;
use App\Exceptions\CuposInsuficientes;
use App\Exceptions\ReactivacionNoPermitida;
use App\Exceptions\ReemplazoNoPermitido;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inscripciones\CancelarOrdenRequest;
use App\Http\Requests\Inscripciones\CargarComprobanteRequest;
use App\Http\Requests\Inscripciones\CorregirParticipanteRequest;
use App\Http\Requests\Inscripciones\ReemplazarParticipanteRequest;
use App\Http\Requests\Inscripciones\RegistrarFacturaRequest;
use App\Mail\FacturaEmitida;
use App\Models\Event;
use App\Models\InvoiceRecord;
use App\Models\Order;
use App\Models\Participant;
use App\Models\Payment;
use App\Models\Ticket;
use App\Support\Auditor;
use App\Support\Forms\ProgramFormField;
use App\Support\GeneradorQr;
use App\Support\Presentar;
use App\Support\Rut;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La orden es la entidad central. Montos y estados salen del flujo del cliente
 * y de Contabilidad, no de un formulario: aquí el equipo solo carga un
 * comprobante que llegó por correo, registra la factura, reemplaza a un
 * participante o anota algo.
 */
class InscripcionController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Order::class);

        $filtros = $request->validate([
            'buscar' => ['nullable', 'string', 'max:120'],
            'evento' => ['nullable', 'integer'],
            'estado' => ['nullable', Rule::enum(OrderStatus::class)],
            'pago' => ['nullable', Rule::enum(PaymentStatus::class)],
            'vista' => ['nullable', 'in:por_vencer,vencidas'],
        ]);

        $sinResolver = [PaymentStatus::EnValidacion->value, PaymentStatus::Aprobado->value];

        $ordenes = Order::query()
            ->withCount('participantesVigentes as participantes')
            ->when($filtros['buscar'] ?? null, fn (Builder $q, string $buscar) => $q->where(
                fn (Builder $o) => $o->where('number', 'like', "%{$buscar}%")
                    ->orWhere('responsible_name', 'like', "%{$buscar}%")
                    ->orWhere('responsible_email', 'like', "%{$buscar}%")
                    ->orWhereHas('payerEntity', fn (Builder $p) => $p->where('name', 'like', "%{$buscar}%")),
            ))
            ->when($filtros['evento'] ?? null, fn (Builder $q, int $evento) => $q->where('event_id', $evento))
            ->when($filtros['estado'] ?? null, fn (Builder $q, string $estado) => $q->where('status', $estado))
            ->when($filtros['pago'] ?? null, fn (Builder $q, string $pago) => $q->where('payment_status', $pago))
            ->when(($filtros['vista'] ?? null) === 'por_vencer', fn (Builder $q) => $q
                ->where('status', OrderStatus::Reservada)
                ->whereNotIn('payment_status', $sinResolver)
                ->whereBetween('reserved_until', [now(), now()->addHours(48)]))
            ->when(($filtros['vista'] ?? null) === 'vencidas', fn (Builder $q) => $q
                ->where('status', OrderStatus::Reservada)
                ->whereNotIn('payment_status', $sinResolver)
                ->where('reserved_until', '<', now()))
            ->latest()
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Order $orden): array => [
                'id' => $orden->id,
                'numero' => $orden->number,
                'responsable' => $orden->responsible_name,
                'correo' => $orden->responsible_email,
                'total' => $orden->total,
                'participantes' => (int) $orden->getAttribute('participantes'),
                'estado' => Presentar::estado($orden->status),
                'pago' => Presentar::estado($orden->payment_status),
                'vence' => $orden->reserved_until?->format('d-m-Y H:i'),
                'vencimiento' => self::vencimiento($orden),
            ]);

        return Inertia::render('inscripciones/index', [
            'ordenes' => $ordenes,
            'filtros' => (object) array_filter($filtros),
            'eventos' => Event::query()->orderBy('name')->pluck('name', 'id'),
            'estados' => collect(OrderStatus::cases())->mapWithKeys(fn (OrderStatus $s): array => [$s->value => $s->label()])->all(),
            'pagos' => collect(PaymentStatus::cases())->mapWithKeys(fn (PaymentStatus $s): array => [$s->value => $s->label()])->all(),
        ]);
    }

    /**
     * Todas las credenciales de la orden, con su QR.
     *
     * Reutiliza la misma vista que recibe el cliente: lo que ve el panel es
     * exactamente lo que le llegó al responsable, sin una plantilla paralela
     * que se desincronice. El enlace del cliente va por magic link, que aquí
     * no existe, así que la orden se resuelve por su id y con la misma policy
     * que protege la ficha.
     */
    public function credenciales(Order $order): Renderable
    {
        Gate::authorize('verCredenciales', $order);

        $order->load('event');

        return view('publico.ticket.orden', [
            'orden' => $order,
            'tickets' => $order->tickets()
                ->vigentes()
                ->with('event', 'order', 'participant.accessType.sessions', 'participant.establishment')
                ->get(),
            'qr' => app(GeneradorQr::class),
        ]);
    }

    public function show(Request $request, Order $order): Response
    {
        Gate::authorize('view', $order);

        $order->load([
            'event',
            'payerEntity',
            'establishments',
            'participants' => fn ($q) => $q->with(['accessType', 'establishment', 'replacedBy', 'accreditation'])->latest('id'),
            'tickets' => fn ($q) => $q->with(['participant', 'accreditation'])->latest('id'),
            'payments',
            'invoiceRecords',
        ]);

        $usuario = $request->user();
        $puedeReemplazar = $usuario->can('update', $order);
        $puedeVerCredenciales = $usuario->can('verCredenciales', $order);
        $limite = $order->event->replacement_deadline;
        $plazoVencido = $limite !== null && $limite->endOfDay()->isPast();

        return Inertia::render('inscripciones/show', [
            'cargos' => ProgramFormField::CARGOS,
            'orden' => [
                'id' => $order->id,
                'numero' => $order->number,
                'estado' => Presentar::estado($order->status),
                'pago' => Presentar::estado($order->payment_status),
                'tipo' => $order->kind->label(),
                'evento' => ['id' => $order->event->id, 'nombre' => $order->event->name],
                'confirmada' => $order->confirmed_at?->format('d-m-Y H:i'),
                'vence' => $order->reserved_until?->format('d-m-Y H:i'),
                'vencimiento' => self::vencimiento($order),
                'total' => $order->total,
                'subtotal' => $order->subtotal,
                'descuento' => $order->discount_amount,
                'descuento_etiqueta' => $order->discount_label,
                'internal_notes' => $order->internal_notes,
                'responsable' => [
                    'nombre' => $order->responsible_name,
                    'cargo' => $order->responsible_position,
                    'correo' => $order->responsible_email,
                    'telefono' => $order->responsible_phone,
                    'institucion' => $order->responsible_institution,
                ],
                'entidad' => $order->payerEntity === null ? null : [
                    'nombre' => $order->payerEntity->name,
                    'rut' => $order->payerEntity->rut,
                    'direccion' => $order->payerEntity->address,
                    'correo' => $order->payerEntity->billing_email,
                ],
            ],
            'participantes' => $order->participants->map(fn (Participant $p): array => [
                'id' => $p->id,
                'nombre' => $p->nombre_completo,
                'first_name' => $p->first_name,
                'last_name' => $p->last_name,
                'correo' => $p->email,
                'cargo' => $p->position,
                'rut' => $p->rut,
                'establecimiento' => $p->establishment?->name,
                'establishment_id' => $p->establishment_id,
                'acceso' => $p->accessType?->name,
                'valor' => $p->unit_price,
                'estado' => Presentar::estado($p->status),
                'reemplazado_por' => $p->replacedBy?->nombre_completo,
                // Lo mismo que verifica ReemplazarParticipante, dicho antes de
                // abrir el formulario y no después de llenarlo.
                'no_reemplazable' => match (true) {
                    $p->status === ParticipantStatus::Reemplazado => 'Ya fue reemplazado.',
                    $p->accreditation !== null => 'Ya fue acreditado y retiró su pulsera.',
                    $plazoVencido => 'El plazo para reemplazar venció el '.$limite->format('d-m-Y').'.',
                    default => null,
                },
            ])->all(),
            'establecimientos' => $order->establishments->pluck('name', 'id'),
            'credenciales' => $order->tickets->map(fn (Ticket $t): array => [
                'id' => $t->id,
                'participante' => $t->participant?->nombre_completo,
                // Código y enlace sirven para entrar al evento: a quien no puede
                // ver credenciales no se le mandan, ni ocultos en la página.
                'codigo' => $puedeVerCredenciales ? $t->code : null,
                'url' => $puedeVerCredenciales ? $t->url() : null,
                'enviada' => $t->emailed_at?->format('d-m-Y H:i'),
                'estado' => match (true) {
                    $t->revoked_at !== null => ['value' => 'revocada', 'label' => 'Anulada', 'color' => 'danger'],
                    $t->accreditation !== null => ['value' => 'acreditada', 'label' => 'Acreditado', 'color' => 'success'],
                    default => ['value' => 'vigente', 'label' => 'Vigente', 'color' => 'info'],
                },
                'acreditado_el' => $t->accreditation?->accredited_at?->format('d-m-Y H:i'),
            ])->all(),
            'pagos' => $usuario->can(Permiso::VerComprobantes->value)
                ? $order->payments->map(fn (Payment $pago): array => [
                    'id' => $pago->id,
                    'estado' => Presentar::estado($pago->status),
                    'monto' => $pago->amount,
                    'recibido' => $pago->created_at->format('d-m-Y H:i'),
                    'informado_por' => $pago->submitted_by_label,
                ])->all()
                : null,
            'facturacion' => $usuario->can(Permiso::GestionarFacturas->value)
                ? [
                    'estado' => Presentar::estado($order->estadoDeFactura()),
                    'documentos' => $order->invoiceRecords->map(fn (InvoiceRecord $f): array => [
                        'id' => $f->id,
                        'tipo' => $f->document_type->label(),
                        'numero' => $f->number,
                        'emitido' => $f->issued_on->format('d-m-Y'),
                        'monto' => $f->amount,
                        'enviada' => $f->sent_at?->format('d-m-Y H:i'),
                        'enviada_a' => $f->sent_to,
                    ])->all(),
                ]
                : null,
            'puede' => [
                'cargarComprobante' => $order->admiteComprobante() && $usuario->can(Permiso::CargarComprobante->value),
                'registrarFactura' => $usuario->can(Permiso::GestionarFacturas->value) && $order->payment_status->liberaCredenciales(),
                'reemplazar' => $puedeReemplazar,
                'editarNotas' => $puedeReemplazar,
                'verCredenciales' => $puedeVerCredenciales,
            ],
            // Solo sobre una reserva vigente; si algo lo impide, el botón lo dice.
            'cancelacion' => $puedeReemplazar && $order->status === OrderStatus::Reservada
                ? ['impedimento' => CancelarOrden::impedimento($order)]
                : null,
            // Para el cliente que pagó tarde. Si ya no hay cupos, lo dice al intentarlo.
            'reactivacion' => $puedeReemplazar && $order->status === OrderStatus::Vencida
                ? ['impedimento' => ReactivarReserva::impedimento($order), 'plazo' => $order->event->calcularVencimientoReserva()->format('d-m-Y H:i')]
                : null,
            'opciones' => [
                'tiposDocumento' => collect(InvoiceDocumentType::cases())->mapWithKeys(fn (InvoiceDocumentType $t): array => [$t->value => $t->label()])->all(),
                'correoFacturacion' => $order->payerEntity?->billing_email,
                'hoy' => now()->format('Y-m-d'),
            ],
        ]);
    }

    public function actualizarNotas(Request $request, Order $order): RedirectResponse
    {
        Gate::authorize('update', $order);

        $order->update($request->validate(['internal_notes' => ['nullable', 'string', 'max:5000']]));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Notas guardadas.']);

        return back();
    }

    public function cargarComprobante(CargarComprobanteRequest $request, Order $order, RegistrarComprobante $registrar): RedirectResponse
    {
        try {
            $registrar(
                orden: $order,
                datos: Arr::except($request->validated(), 'proof'),
                archivo: $request->file('proof'),
                usuario: $request->user(),
            );
        } catch (ComprobanteNoAceptado $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Comprobante registrado. Quedó pendiente de validación en Contabilidad.']);

        return back();
    }

    public function registrarFactura(RegistrarFacturaRequest $request, Order $order): RedirectResponse
    {
        $datos = $request->validated();
        $archivo = $request->file('archivo');
        $disco = config('filesystems.private_disk');
        $ruta = $archivo?->store('facturas/'.$order->getKey(), $disco);

        $factura = $order->invoiceRecords()->create([
            ...Arr::except($datos, ['archivo', 'enviar']),
            'disk' => $ruta ? $disco : null,
            'path' => $ruta,
            'original_name' => $archivo?->getClientOriginalName(),
            'size' => $archivo?->getSize(),
            'registered_by_user_id' => $request->user()->getKey(),
            'registered_by_label' => $request->user()->name,
        ]);

        $destinatarios = ($datos['enviar'] ?? true)
            ? $this->enviarFactura($factura->load('order.event', 'order.payerEntity'))
            : [];

        Auditor::registrar(
            sobre: $order,
            accion: 'factura.registrada',
            propiedades: [
                'documento' => $factura->descripcion(),
                'monto' => $factura->amount,
                'enviada_a' => $destinatarios,
            ],
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $destinatarios === []
                ? $factura->descripcion().' registrada.'
                : $factura->descripcion().' registrada y enviada a '.implode(', ', $destinatarios).'.',
        ]);

        return back();
    }

    /**
     * Envía la factura y deja constancia de a quiénes llegó.
     *
     * Van los dos: el responsable de la inscripción y la entidad pagadora.
     * Puede que la entidad pagadora esté con licencia y el responsable se lo
     * avise, o al revés; mandarlo a uno solo hace que se pierda. La copia a
     * administración es la trazabilidad, y su dirección vive en configuración.
     *
     * @return array<int, string>
     */
    private function enviarFactura(InvoiceRecord $factura): array
    {
        $orden = $factura->order;

        $destinatarios = array_values(array_unique(array_filter([
            $orden->responsible_email,
            $orden->payerEntity?->billing_email,
        ])));

        if ($destinatarios === []) {
            return [];
        }

        $correo = Mail::to($destinatarios);

        if ($copia = config('facturacion.copia_administracion')) {
            $correo->cc($copia);
        }

        $correo->queue(new FacturaEmitida($factura));

        $factura->forceFill([
            'sent_at' => now(),
            'sent_to' => implode(', ', $destinatarios),
        ])->save();

        return $destinatarios;
    }

    public function reemplazar(ReemplazarParticipanteRequest $request, Order $order, Participant $participant, ReemplazarParticipante $reemplazar): RedirectResponse
    {
        $datos = $request->datosNormalizados();

        try {
            $entrante = $reemplazar(
                saliente: $participant,
                datos: Arr::except($datos, 'motivo'),
                usuario: $request->user(),
                motivo: $datos['motivo'] ?? null,
            );
        } catch (ReemplazoNoPermitido $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);

            return back();
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $entrante->nombre_completo.' reemplaza a '.$participant->nombre_completo.'. La credencial anterior quedó anulada.',
        ]);

        return back();
    }

    public function cancelar(CancelarOrdenRequest $request, Order $order, CancelarOrden $cancelar): RedirectResponse
    {
        try {
            $cancelar($order, $request->validated('motivo'));
        } catch (CancelacionNoPermitida $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Inscripción cancelada. Sus cupos quedaron libres.']);

        return back();
    }

    public function reactivar(Order $order, ReactivarReserva $reactivar): RedirectResponse
    {
        Gate::authorize('update', $order);

        try {
            $orden = $reactivar($order);
        } catch (ReactivacionNoPermitida|CuposInsuficientes $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Reserva reactivada. Vence el '.$orden->reserved_until->format('d-m-Y H:i').'.']);

        return back();
    }

    /**
     * Corrige un dato mal escrito. La credencial se dibuja con los datos
     * vigentes, así que el nombre corregido sale en ella sin reemitirla.
     */
    public function corregirParticipante(CorregirParticipanteRequest $request, Order $order, Participant $participant): RedirectResponse
    {
        $datos = $request->datosNormalizados();
        $datos['rut'] = Rut::normalizar($datos['rut'] ?? null);

        $participant->fill($datos);
        $antes = array_intersect_key($participant->getOriginal(), $participant->getDirty());
        $despues = $participant->getDirty();

        if ($despues !== []) {
            $participant->save();

            Auditor::registrar(
                sobre: $participant,
                accion: 'participante.corregido',
                propiedades: ['antes' => $antes, 'despues' => $despues],
            );
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Datos de '.$participant->nombre_completo.' corregidos.']);

        return back();
    }

    /**
     * Cómo leer el vencimiento de una reserva, en una palabra para el color.
     *
     * @return array{tono: string, nota: string|null}|null
     */
    private static function vencimiento(Order $orden): ?array
    {
        if ($orden->status !== OrderStatus::Reservada || $orden->reserved_until === null) {
            return null;
        }

        if ($orden->payment_status->congelaElVencimiento()) {
            return ['tono' => 'info', 'nota' => 'En validación: no vence'];
        }

        if ($orden->reserved_until->isPast()) {
            return ['tono' => 'danger', 'nota' => 'Vencida'];
        }

        if ($orden->reserved_until->diffInHours() < 48) {
            return ['tono' => 'warning', 'nota' => 'Vence pronto'];
        }

        return null;
    }
}
