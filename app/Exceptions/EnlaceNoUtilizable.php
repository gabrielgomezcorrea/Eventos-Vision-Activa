<?php

namespace App\Exceptions;

use App\Models\Event;
use Exception;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * El cliente llego con un enlace que no sirve para lo que intentaba hacer.
 *
 * Se renderiza como pagina explicada y no como 403 crudo: quien recibe
 * "Forbidden" no tiene forma de saber que basta con pedir otro enlace, y
 * termina llamando por telefono.
 */
class EnlaceNoUtilizable extends Exception
{
    private function __construct(
        public readonly string $titulo,
        public readonly string $explicacion,
        public readonly ?string $accionUrl = null,
        public readonly ?string $accionTexto = null,
    ) {
        parent::__construct($titulo);
    }

    /** El enlace no existe, fue revocado o expiro. */
    public static function vencido(?Event $event): self
    {
        return new self(
            'Este enlace ya no es válido',
            'Puede haber expirado o haber sido reemplazado por uno más reciente. Pide uno nuevo con tu correo: no perderás nada de lo que ya registraste.',
            $event ? route('inscripcion.inicio', ['event' => $event->slug]) : null,
            'Pedir un enlace nuevo',
        );
    }

    /** El enlace sirve, pero la inscripción ya salió de la etapa editable. */
    public static function ordenNoEditable(string $token): self
    {
        return new self(
            'Tu inscripción ya está confirmada',
            'Por eso no se pueden cambiar los participantes ni los datos desde aquí. Puedes revisar su estado, las instrucciones de pago y subir tu comprobante.',
            route('inscripcion.estado', ['token' => $token]),
            'Ver el estado de mi inscripción',
        );
    }

    public function render(Request $request): Response
    {
        return response()->view('publico.inscripcion.enlace-invalido', [
            'titulo' => $this->titulo,
            'explicacion' => $this->explicacion,
            'accionUrl' => $this->accionUrl,
            'accionTexto' => $this->accionTexto,
        ], 403);
    }
}
