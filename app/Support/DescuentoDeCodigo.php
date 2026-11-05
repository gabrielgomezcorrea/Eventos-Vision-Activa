<?php

namespace App\Support;

use App\Enums\OrderStatus;
use App\Enums\TipoDescuento;
use App\Models\DiscountCode;
use App\Models\Order;
use App\Models\Participant;

/**
 * Cuánto descuenta un código en una inscripción.
 *
 * El código cubre el recorrido completo (todas las inscripciones del mismo
 * conjunto), como un cupón sobre el total del carro:
 * - porcentaje: el mismo % sobre lo que paga cada inscripción;
 * - monto fijo: una sola vez por recorrido, sin importar cuántos colegios
 *   traiga. Con varios colegios se reparte en proporción a lo que paga cada
 *   uno, para que cada factura muestre su parte.
 */
class DescuentoDeCodigo
{
    /**
     * @param  int|null  $asignado  La parte del monto fijo ya repartida para esta
     *                              inscripción (al confirmar varios colegios juntos).
     */
    public static function para(DiscountCode $codigo, Order $orden, int $subtotal, ?int $asignado = null): int
    {
        if ($codigo->type === TipoDescuento::Porcentaje) {
            return $codigo->calcular($subtotal);
        }

        if ($codigo->type === TipoDescuento::PorJornada) {
            return self::porJornada($codigo, $orden);
        }

        if ($asignado !== null) {
            return max(0, min($asignado, $subtotal));
        }

        return max(0, min(self::montoDisponible($codigo, $orden), $subtotal));
    }

    /**
     * «Por jornada»: por cada participante vigente, lo que su precio vigente
     * (normal o anticipado) supera al precio especial de su acceso. Sin acceso
     * o con un acceso que el código no lista, paga lo normal. Si el anticipado
     * ya es más bajo que el especial, la resta da 0: se cobra el anticipado.
     */
    public static function porJornada(DiscountCode $codigo, Order $orden): int
    {
        $codigo->loadMissing('preciosPorAcceso');
        $orden->loadMissing('participantesVigentes.accessType');

        return (int) $orden->participantesVigentes->sum(
            fn ($participante): int => self::rebajaDe($codigo, $orden, $participante),
        );
    }

    /**
     * Lo que paga este participante con el código «Por jornada», o null si el
     * código no le cambia el precio (otro tipo, acceso no listado o un precio
     * vigente que ya es igual o más bajo).
     */
    public static function precioConCodigo(?DiscountCode $codigo, Order $orden, Participant $participante): ?int
    {
        if ($codigo?->type !== TipoDescuento::PorJornada) {
            return null;
        }

        $rebaja = self::rebajaDe($codigo, $orden, $participante);

        return $rebaja > 0 ? self::vigenteDe($orden, $participante) - $rebaja : null;
    }

    private static function vigenteDe(Order $orden, Participant $participante): int
    {
        return $orden->esBorrador()
            ? (int) ($participante->accessType?->precioVigente() ?? 0)
            : (int) $participante->unit_price;
    }

    private static function rebajaDe(DiscountCode $codigo, Order $orden, Participant $participante): int
    {
        if ($participante->access_type_id === null) {
            return 0;
        }

        $especial = $codigo->precioEspecialPara($participante->access_type_id);

        return $especial === null ? 0 : max(0, self::vigenteDe($orden, $participante) - $especial);
    }

    /**
     * Lo que queda del monto fijo en el recorrido de esta inscripción: el monto
     * menos lo que ya usaron sus otras inscripciones vivas con el mismo código.
     */
    public static function montoDisponible(DiscountCode $codigo, Order $orden): int
    {
        if ($orden->group_id === null) {
            return $codigo->value;
        }

        $usado = (int) Order::query()
            ->where('group_id', $orden->group_id)
            ->where('discount_code_id', $codigo->getKey())
            ->whereKeyNot($orden->getKey())
            ->whereIn('status', [OrderStatus::Reservada, OrderStatus::Finalizada])
            ->sum('discount_amount');

        return max(0, $codigo->value - $usado);
    }

    /**
     * Reparte un monto entre inscripciones en proporción a su subtotal. La
     * última se lleva el resto del redondeo; nadie recibe más que su subtotal.
     *
     * @param  array<int|string, int>  $subtotales
     * @return array<int|string, int>
     */
    public static function repartir(int $monto, array $subtotales): array
    {
        $total = array_sum($subtotales);
        $monto = min($monto, $total);
        $partes = [];
        $repartido = 0;
        $claves = array_keys($subtotales);

        foreach ($claves as $indice => $clave) {
            $parte = $indice === count($claves) - 1 || $total === 0
                ? $monto - $repartido
                : (int) floor($monto * $subtotales[$clave] / $total);

            $partes[$clave] = max(0, min($parte, $subtotales[$clave]));
            $repartido += $partes[$clave];
        }

        return $partes;
    }
}
