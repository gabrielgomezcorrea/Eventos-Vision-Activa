<?php

namespace App\Exceptions;

use App\Enums\PaymentReviewAction;
use App\Models\Payment;
use RuntimeException;

class RevisionNoValida extends RuntimeException
{
    public static function porqueYaFueResuelto(Payment $pago): self
    {
        return new self(
            "Este comprobante ya fue resuelto como \"{$pago->status->label()}\". Actualiza la página para ver el estado vigente."
        );
    }

    public static function porqueFaltaComentario(PaymentReviewAction $accion): self
    {
        return new self(
            mb_strtolower($accion->label()) === 'observado'
                ? 'Indica qué debe corregir el cliente.'
                : 'Indica el motivo del rechazo.'
        );
    }
}
