<?php

namespace App\Actions;

use App\Exceptions\CodigoNoAplicable;
use App\Models\DiscountCode;
use App\Models\Order;
use App\Support\CodigoDeDescuento;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Asocia un código de descuento a un borrador. Todavía no consume usos: eso
 * ocurre al confirmar, con un UPDATE condicional (`DiscountCode::tomarPersona`).
 *
 * Los intentos fallidos se limitan por inscripción: con el alfabeto de los
 * códigos, adivinar uno vigente es inviable si no se pueden probar miles.
 */
class AplicarCodigoDeDescuento
{
    public const INTENTOS = 5;

    public const BLOQUEO_SEGUNDOS = 600;

    /** @throws CodigoNoAplicable */
    public function __invoke(Order $borrador, string $ingresado, ?string $correoDelEnlace = null): DiscountCode
    {
        // La persona del código es el responsable; si aún no lo completó, quien
        // abrió el enlace (el solicitante). Se fija antes de revisar el código:
        // "una sola vez" y el tope cuentan por ella.
        $persona = mb_strtolower(trim(filled($borrador->responsible_email) ? $borrador->responsible_email : (string) $correoDelEnlace));
        $borrador->discount_code_email = $persona;

        $llave = 'codigo-de-descuento:'.$borrador->getKey();

        if (RateLimiter::tooManyAttempts($llave, self::INTENTOS)) {
            throw CodigoNoAplicable::porque('Probaste muchos códigos. Espera unos minutos y vuelve a intentar.');
        }

        // Un código de otro evento responde igual que uno que no existe.
        $codigo = DiscountCode::query()
            ->where('event_id', $borrador->event_id)
            ->where('code', CodigoDeDescuento::normalizar($ingresado))
            ->first();

        $motivo = $codigo === null
            ? 'Este código no es válido. Revisa que esté bien escrito.'
            : $codigo->impedimentoPara($borrador);

        if ($motivo !== null) {
            RateLimiter::hit($llave, self::BLOQUEO_SEGUNDOS);

            throw CodigoNoAplicable::porque($motivo);
        }

        // No cuenta como intento: la persona sí escribió un código válido.
        $previos = $borrador->participantesConCodigoPrevio();

        if ($previos->isNotEmpty()) {
            throw CodigoNoAplicable::porque(DiscountCode::mensajePorCodigoPrevio($previos));
        }

        RateLimiter::clear($llave);

        $borrador->forceFill([
            'discount_code_id' => $codigo->getKey(),
            'discount_code_email' => $persona,
        ])->save();

        return $codigo;
    }
}
