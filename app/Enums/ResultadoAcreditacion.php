<?php

namespace App\Enums;

/**
 * Desenlace de resolver una credencial en la puerta del evento.
 *
 * Cada caso tiene un mensaje propio: mostrar "QR inválido" para todo genera
 * confusión operativa, porque un reescaneo válido y un código inexistente son
 * situaciones muy distintas.
 */
enum ResultadoAcreditacion: string
{
    case Valida = 'valida';
    case YaAcreditado = 'ya_acreditado';
    case Anulada = 'anulada';
    case PagoNoAprobado = 'pago_no_aprobado';
    case OtroEvento = 'otro_evento';
    case NoEncontrada = 'no_encontrada';

    public function titulo(): string
    {
        return match ($this) {
            self::Valida => 'Credencial válida',
            self::YaAcreditado => 'Participante ya acreditado',
            self::Anulada => 'Credencial anulada',
            self::PagoNoAprobado => 'Pago no confirmado',
            self::OtroEvento => 'Credencial de otro evento',
            self::NoEncontrada => 'No encontramos esta credencial',
        };
    }

    public function mensaje(): string
    {
        return match ($this) {
            self::Valida => 'Entrega la pulsera y confirma la acreditación.',
            self::YaAcreditado => 'Esta persona ya retiró su pulsera. No corresponde entregar otra.',
            self::Anulada => 'Fue reemplazada por otra credencial. Busca al participante por su nombre para ver la vigente.',
            self::PagoNoAprobado => 'El pago de esta inscripción no está confirmado. Deriva el caso a Contabilidad; no se resuelve en la puerta.',
            self::OtroEvento => 'Esta credencial pertenece a otro evento. Cambia el evento seleccionado arriba y vuelve a escanear.',
            self::NoEncontrada => 'Revisa el código o busca al participante por su nombre, RUT o número de inscripción.',
        };
    }

    /** Color del bloque de resultado. Debe distinguirse de un vistazo. */
    public function color(): string
    {
        return match ($this) {
            self::Valida => 'exito',
            self::YaAcreditado => 'aviso',
            self::Anulada, self::PagoNoAprobado, self::OtroEvento => 'aviso',
            self::NoEncontrada => 'error',
        };
    }

    public function permiteAcreditar(): bool
    {
        return $this === self::Valida;
    }
}
