<?php

namespace App\Actions;

use App\Models\Ticket;
use App\Support\Acreditacion\Resultado;

/**
 * Resuelve lo que llega desde la puerta: el token de un QR escaneado o un
 * código de respaldo dictado a mano.
 */
class ResolverCredencial
{
    private const RELACIONES = [
        'event',
        'order',
        'participant.accessType.sessions',
        'participant.establishment',
        'accreditation.user',
    ];

    public function __invoke(string $entrada): Resultado
    {
        $ticket = $this->buscar(trim($entrada));

        if (! $ticket) {
            return Resultado::noEncontrada();
        }

        if (! $ticket->estaVigente()) {
            return Resultado::anulada($ticket);
        }

        // Defensivo: las credenciales solo se emiten con el pago aprobado, pero
        // un pago podría revertirse después. En la puerta no se aprueban pagos.
        if (! $ticket->order->payment_status->liberaCredenciales()) {
            return Resultado::pagoNoAprobado($ticket);
        }

        if ($acreditacion = $ticket->accreditation) {
            return Resultado::yaAcreditado($ticket, $acreditacion);
        }

        return Resultado::valida($ticket);
    }

    /**
     * Acepta el token completo, la URL que trae el QR, o el código de respaldo
     * escrito de cualquier forma.
     */
    private function buscar(string $entrada): ?Ticket
    {
        if ($entrada === '') {
            return null;
        }

        // El lector puede entregar la URL completa del QR.
        if (str_contains($entrada, '/t/')) {
            $entrada = (string) preg_replace('#^.*/t/#', '', $entrada);
            $entrada = explode('?', $entrada)[0];
        }

        if ($ticket = Ticket::resolver($entrada)) {
            return $ticket->load(self::RELACIONES);
        }

        return Ticket::query()
            ->where('code', Ticket::normalizarCodigo($entrada))
            ->with(self::RELACIONES)
            ->first();
    }
}
