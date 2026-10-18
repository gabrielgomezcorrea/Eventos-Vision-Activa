<?php

namespace App\Support;

use App\Enums\OrderStatus;
use App\Enums\TipoDescuento;
use App\Models\DiscountCode;
use App\Models\Order;

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

        if ($asignado !== null) {
            return max(0, min($asignado, $subtotal));
        }

        return max(0, min(self::montoDisponible($codigo, $orden), $subtotal));
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
