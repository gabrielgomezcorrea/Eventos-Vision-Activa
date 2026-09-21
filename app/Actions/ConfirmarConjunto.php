<?php

namespace App\Actions;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\CuposInsuficientes;
use App\Exceptions\OrdenNoConfirmable;
use App\Mail\ConjuntoConfirmado;
use App\Models\Order;
use App\Models\Participant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Confirma un borrador con más de un colegio: lo separa en una orden por
 * establecimiento, todas en el mismo conjunto, y confirma cada una con
 * ConfirmarOrden dentro de una sola transacción.
 *
 * Todo o nada: si a un colegio le falta cupo, no se confirma ninguno del
 * conjunto. El cliente apretó un solo botón.
 */
class ConfirmarConjunto
{
    public function __construct(private readonly ConfirmarOrden $confirmar) {}

    /**
     * @return array<int, Order>
     *
     * @throws OrdenNoConfirmable
     * @throws CuposInsuficientes
     */
    public function __invoke(Order $borrador): array
    {
        return DB::transaction(function () use ($borrador) {
            $ordenes = $this->dividirPorEstablecimiento($borrador);

            // ConfirmarOrden no muta la instancia que recibe: recarga su
            // propia copia con lockForUpdate y devuelve esa, ya con folio y
            // precio congelados. Sin capturar el retorno, el correo del
            // conjunto se arma con los datos viejos del borrador.
            $ordenes = array_map(
                fn (Order $orden) => ($this->confirmar)($orden, actorLabel: $orden->responsible_email, enviarCorreo: false),
                $ordenes,
            );

            $grupo = $ordenes[0]->group()->firstOrFail();
            Mail::to($borrador->responsible_email)->queue(new ConjuntoConfirmado($grupo, collect($ordenes)));

            return $ordenes;
        });
    }

    /**
     * El primer establecimiento se queda con el borrador original: crear una
     * orden extra también para el caso más común, uno solo, es lo que después
     * nadie mantiene. Los siguientes nacen como copias del borrador, con sus
     * propios participantes y el mismo conjunto.
     *
     * @return array<int, Order>
     */
    private function dividirPorEstablecimiento(Order $borrador): array
    {
        $establecimientos = $borrador->establishments;
        $ordenes = [];

        foreach ($establecimientos as $indice => $establecimiento) {
            if ($indice === 0) {
                $borrador->establishments()->sync([$establecimiento->getKey()]);
                // sync() no refresca la relación ya cargada en memoria: sin
                // esto, $borrador->establishments seguiría mostrando los dos
                // colegios originales.
                $borrador->load('establishments');
                $ordenes[] = $borrador;

                continue;
            }

            // replicate() copia también las relaciones ya cargadas (los dos
            // colegios del borrador) y deja sin definir los atributos
            // excluidos, sin aplicar los valores por defecto del modelo: se
            // limpia y se fija todo a mano.
            $hermana = $borrador->replicate([
                'number', 'status', 'payment_status', 'total', 'subtotal',
                'discount_amount', 'discount_label', 'confirmed_at', 'reserved_until',
                'applied_tariff', 'applied_tariff_until', 'cancelled_at',
            ]);
            $hermana->unsetRelation('establishments');
            $hermana->forceFill([
                'status' => OrderStatus::Borrador,
                'payment_status' => PaymentStatus::Pendiente,
                'total' => 0,
            ]);
            $hermana->save();
            $hermana->establishments()->sync([$establecimiento->getKey()]);
            $hermana->load('establishments');

            Participant::query()
                ->where('order_id', $borrador->getKey())
                ->where('establishment_id', $establecimiento->getKey())
                ->update(['order_id' => $hermana->getKey()]);

            $ordenes[] = $hermana;
        }

        return $ordenes;
    }
}
