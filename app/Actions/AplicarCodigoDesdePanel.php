<?php

namespace App\Actions;

use App\Enums\OrderKind;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\CodigoNoAplicable;
use App\Exceptions\TicketsNoLiberables;
use App\Models\DiscountCode;
use App\Models\Order;
use App\Models\Participant;
use App\Models\User;
use App\Support\Auditor;
use App\Support\CodigoDeDescuento;
use App\Support\DescuentoDeCodigo;
use App\Support\EnvioDeCredenciales;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Aplica un código a una inscripción ya confirmada que no lo tiene: el equipo
 * desde el panel o el cliente desde el portal (lo olvidó al confirmar). Misma validación y mismo cálculo que al confirmar,
 * pero sin devolver plata: si lo aprobado supera el nuevo total no se aplica.
 * Es interno: no manda correo. Si lo aprobado alcanza el total, queda pagada y
 * salen las credenciales, como cuando Contabilidad aprueba el último abono.
 */
class AplicarCodigoDesdePanel
{
    /**
     * Sin usuario lo aplica el cliente: cuenta sus intentos fallidos igual que
     * en el borrador (`AplicarCodigoDeDescuento`) y la auditoría lleva su correo.
     *
     * @throws CodigoNoAplicable
     */
    public function __invoke(Order $orden, string $ingresado, ?User $usuario = null, ?string $actorLabel = null): Order
    {
        $llave = 'codigo-de-descuento:'.$orden->getKey();

        if ($usuario === null && RateLimiter::tooManyAttempts($llave, AplicarCodigoDeDescuento::INTENTOS)) {
            throw CodigoNoAplicable::porque('Probaste muchos códigos. Espera unos minutos y vuelve a intentar.');
        }

        try {
            $resultado = $this->aplicar($orden, $ingresado, $actorLabel);
        } catch (CodigoNoAplicable $e) {
            if ($usuario === null) {
                RateLimiter::hit($llave, AplicarCodigoDeDescuento::BLOQUEO_SEGUNDOS);
            }

            throw $e;
        }

        if ($usuario === null) {
            RateLimiter::clear($llave);
        }

        if ($resultado->payment_status === PaymentStatus::Aprobado) {
            try {
                app(EmitirTickets::class)($resultado);
                $resultado = $resultado->fresh();
                EnvioDeCredenciales::alResponsable($resultado);
            } catch (TicketsNoLiberables $e) {
                Log::error('No se pudieron emitir las credenciales tras aplicar el código.', [
                    'orden' => $resultado->number,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $resultado;
    }

    private function aplicar(Order $orden, string $ingresado, ?string $actorLabel): Order
    {
        return DB::transaction(function () use ($orden, $ingresado, $actorLabel): Order {
            $fresca = Order::whereKey($orden->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($fresca->status, [OrderStatus::Reservada, OrderStatus::Finalizada], true) || $fresca->kind === OrderKind::Invitado) {
                throw CodigoNoAplicable::porque('Solo se aplica a inscripciones confirmadas.');
            }

            if ($fresca->discount_code_id !== null) {
                throw CodigoNoAplicable::porque('Esta inscripción ya tiene un código. Quítalo primero.');
            }

            $codigo = DiscountCode::query()
                ->where('event_id', $fresca->event_id)
                ->where('code', CodigoDeDescuento::normalizar($ingresado))
                ->first();

            if ($codigo === null) {
                throw CodigoNoAplicable::porque('Este código no es válido. Revisa que esté bien escrito.');
            }

            // La persona del código es el responsable, como al aplicarlo el cliente.
            $fresca->discount_code_email = mb_strtolower(trim($fresca->responsible_email));

            if (($motivo = $codigo->impedimentoPara($fresca)) !== null) {
                throw CodigoNoAplicable::porque($motivo);
            }

            if (($previos = $fresca->participantesConCodigoPrevio())->isNotEmpty()) {
                throw CodigoNoAplicable::porque(DiscountCode::mensajePorCodigoPrevio($previos));
            }

            if ($fresca->tieneAbonoEnRevision()) {
                throw CodigoNoAplicable::porque('Hay un comprobante en revisión. Aplica el código después de que Contabilidad lo revise.');
            }

            $fresca->unsetRelation('participantesVigentes');
            $fresca->load('event.discountTiers', 'participantesVigentes');

            $subtotal = (int) $fresca->participantesVigentes->sum(fn (Participant $p): int => (int) $p->unit_price);
            $descuento = DescuentoDeCodigo::para($codigo, $fresca, $subtotal);
            $totalNuevo = $subtotal - $descuento;
            $pagado = $fresca->pagado();

            if ($pagado > $totalNuevo) {
                throw CodigoNoAplicable::porque('Lo ya aprobado ($'.number_format($pagado, 0, ',', '.').') supera el total con el código ($'.number_format($totalNuevo, 0, ',', '.').'). No se aplica: habría que devolver plata.');
            }

            if (! $codigo->tomarPersona($fresca)) {
                throw CodigoNoAplicable::porque('Este código ya alcanzó el máximo de personas que pueden usarlo.');
            }

            $totalAnterior = (int) $fresca->total;
            $estadoAnterior = $fresca->payment_status;

            $fresca->forceFill([
                'discount_code_id' => $codigo->getKey(),
                'subtotal' => $subtotal,
                'discount_amount' => $descuento,
                'discount_label' => 'Código '.$codigo->formateado().' ('.$codigo->etiqueta().')',
                'total' => $totalNuevo,
            ]);

            if ($totalNuevo - $pagado === 0 && ($pagado > 0 || $totalNuevo === 0)) {
                $fresca->payment_status = PaymentStatus::Aprobado;
            }

            $fresca->save();

            Auditor::registrar(
                sobre: $fresca,
                accion: 'orden.codigo_aplicado',
                estadoAnterior: $estadoAnterior->value,
                estadoNuevo: $fresca->payment_status->value,
                propiedades: [
                    'codigo' => $codigo->formateado(),
                    'total_anterior' => $totalAnterior,
                    'total_nuevo' => $totalNuevo,
                ],
                actorLabel: $actorLabel,
            );

            return $fresca;
        });
    }
}
