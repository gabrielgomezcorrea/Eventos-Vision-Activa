<?php

namespace App\Actions;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\CuposInsuficientes;
use App\Exceptions\OrdenNoConfirmable;
use App\Mail\OrdenConfirmada;
use App\Models\DiscountCode;
use App\Models\Order;
use App\Models\Participant;
use App\Models\ProgramRequest;
use App\Support\Auditor;
use App\Support\DescuentoDeCodigo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Confirma un borrador: congela precios, asigna folio, reserva cupos y fija
 * el vencimiento.
 *
 * Todo ocurre en una sola transacción. Si una jornada se queda sin cupo a mitad
 * de camino, se revierte completo: no puede quedar una orden con folio asignado
 * y cupos a medias.
 */
class ConfirmarOrden
{
    public function __construct(
        private readonly ReservarCupos $cupos,
        private readonly GenerarNumeroDeOrden $numero,
        private readonly EmitirTickets $tickets,
    ) {}

    /**
     * @throws OrdenNoConfirmable
     * @throws CuposInsuficientes
     */
    public function __invoke(Order $orden, ?string $actorLabel = null, bool $enviarCorreo = true, ?int $descuentoAsignado = null): Order
    {
        $this->verificar($orden);

        $orden = DB::transaction(function () use ($orden, $descuentoAsignado) {
            // Se recarga dentro de la transacción para no confirmar dos veces
            // si llegan dos envíos del mismo formulario.
            $fresca = Order::whereKey($orden->getKey())->lockForUpdate()->firstOrFail();

            if (! $fresca->esBorrador()) {
                throw OrdenNoConfirmable::porqueYaNoEsBorrador();
            }

            $fresca->load('event', 'participants.accessType.sessions');

            // El precio se congela en cada participante: cambiar después el
            // valor de un acceso no debe alterar órdenes ya confirmadas.
            //
            // Se cobra el precio vigente al confirmar, no al empezar: quien
            // demoró y confirmó después del alza paga el valor nuevo, y el
            // alza estuvo anunciada con su fecha desde el primer correo.
            $anticipado = false;
            $rigeHasta = null;

            foreach ($fresca->participantesVigentes as $participante) {
                $acceso = $participante->accessType;

                if ($acceso?->tieneDescuentoAnticipado()) {
                    $anticipado = true;
                    $rigeHasta = $acceso->early_until;
                }

                $participante->forceFill([
                    'unit_price' => (int) ($acceso?->precioVigente() ?? 0),
                ])->save();
            }

            $fresca->refresh()->load('participants.accessType.sessions', 'event.discountTiers');

            $this->cupos->tomar($fresca->consumoDeCupos());

            $subtotal = (int) $fresca->participantesVigentes->sum(
                fn (Participant $p) => $p->unit_price
            );

            // El descuento se congela junto con el precio: si después
            // reemplazan a alguien, el monto no se mueve. El conteo depende
            // de cómo el evento decidió contar un conjunto de varios colegios.
            if ($fresca->event->cuentaDescuentoPorConjunto() && $fresca->group_id !== null) {
                $fresca->load('group.orders.participantesVigentes');
            }

            // Con código, el descuento por cantidad no aplica: nunca dos
            // descuentos sobre la misma inscripción. Los usos se toman aquí,
            // no al aplicarlo, y si no alcanzan se revierte todo.
            $codigo = $fresca->discount_code_id !== null ? DiscountCode::find($fresca->discount_code_id) : null;

            if ($codigo !== null && ($previos = $fresca->participantesConCodigoPrevio())->isNotEmpty()) {
                throw OrdenNoConfirmable::porque(DiscountCode::mensajePorCodigoPrevio($previos));
            }

            // El cupo del código es por persona (correo del solicitante), no por participante.
            if ($codigo !== null && ($motivo = $codigo->impedimentoPara($fresca)) !== null) {
                throw OrdenNoConfirmable::porque($motivo);
            }

            if ($codigo !== null && ! $codigo->tomarPersona($fresca)) {
                throw OrdenNoConfirmable::porque('Este código ya alcanzó el máximo de personas que pueden usarlo.');
            }

            $tramo = $codigo === null ? $fresca->event->tramoDeDescuento($fresca->participantesParaDescuento()) : null;
            $descuento = $codigo !== null
                ? DescuentoDeCodigo::para($codigo, $fresca, $subtotal, $descuentoAsignado)
                : ($tramo?->calcular($subtotal) ?? 0);
            $sinCosto = $codigo !== null && $subtotal - $descuento === 0;

            $fresca->forceFill([
                'number' => ($this->numero)($fresca->event),
                'status' => OrderStatus::Reservada,
                'subtotal' => $subtotal,
                'discount_amount' => $descuento,
                'discount_label' => $codigo !== null
                    ? 'Código '.$codigo->formateado().' ('.$codigo->etiqueta().')'
                    : $tramo?->etiqueta(),
                'total' => $subtotal - $descuento,
                // Sin nada que pagar no hay comprobante ni revisión: queda aprobada.
                'payment_status' => $sinCosto ? PaymentStatus::Aprobado : $fresca->payment_status,
                'applied_tariff' => $anticipado ? 'anticipado' : 'normal',
                'applied_tariff_until' => $anticipado ? $rigeHasta : null,
                'confirmed_at' => now(),
                'reserved_until' => $fresca->event->calcularVencimientoReserva(),
            ])->save();

            return $fresca;
        });

        // Quien pidió el programa con este correo ya se inscribió: queda marcado
        // solo, sin que Coordinación tenga que hacerlo a mano.
        ProgramRequest::query()
            ->where('event_id', $orden->event_id)
            ->where(fn ($consulta) => $consulta
                ->where('email', mb_strtolower($orden->responsible_email))
                ->when($orden->program_request_id, fn ($c, $id) => $c->orWhere('id', $id)))
            ->whereNull('converted_at')
            ->update(['converted_at' => now()]);

        Auditor::registrar(
            sobre: $orden,
            accion: 'orden.confirmada',
            estadoAnterior: OrderStatus::Borrador->value,
            estadoNuevo: OrderStatus::Reservada->value,
            propiedades: [
                'numero' => $orden->number,
                'subtotal' => $orden->subtotal,
                'descuento' => $orden->discount_amount,
                'total' => $orden->total,
                'codigo' => $orden->discount_code_id !== null ? $orden->discount_label : null,
            ],
            actorLabel: $actorLabel,
        );

        // Con un código que la deja en $0 las credenciales salen al instante:
        // no hay instrucciones de pago que mandar.
        if ($orden->discount_code_id !== null && (int) $orden->total === 0) {
            ($this->tickets)($orden);

            return $orden;
        }

        // Un conjunto de varios colegios manda un solo correo con todos
        // adentro (App\Actions\ConfirmarConjunto), no uno por colegio.
        if ($enviarCorreo && $orden->notify_client) {
            Mail::to($orden->responsible_email)->queue(new OrdenConfirmada($orden));
        }

        return $orden;
    }

    /** @throws OrdenNoConfirmable */
    private function verificar(Order $orden): void
    {
        if (! $orden->esBorrador()) {
            throw OrdenNoConfirmable::porqueYaNoEsBorrador();
        }

        if (! $orden->event->admiteInscripciones()) {
            throw OrdenNoConfirmable::porque('Este evento ya no admite inscripciones.');
        }

        $faltantes = $this->faltantes($orden);

        if ($faltantes !== []) {
            throw OrdenNoConfirmable::porque($faltantes[0]['mensaje']);
        }
    }

    /**
     * Lo que le falta a un borrador para poder confirmarse, en el orden en que
     * conviene completarlo. Lo usa la pantalla para decirlo antes de que el
     * cliente apriete, y `verificar` para rechazarlo igual en el servidor.
     *
     * @return array<int, array{bloque: string, mensaje: string}>
     */
    public function faltantes(Order $orden): array
    {
        $faltantes = [];

        if (blank($orden->responsible_name) || blank($orden->responsible_lastname) || blank($orden->responsible_email)) {
            $faltantes[] = ['bloque' => 'responsable', 'mensaje' => 'Falta registrar al responsable de la inscripción.'];
        }

        if ($orden->payer_entity_id === null) {
            $faltantes[] = ['bloque' => 'pagador', 'mensaje' => 'Falta registrar la entidad pagadora.'];
        }

        if ($orden->participantesVigentes()->count() === 0) {
            $faltantes[] = ['bloque' => 'participantes', 'mensaje' => 'Agrega al menos un participante antes de confirmar.'];
        }

        return $faltantes;
    }
}
