<?php

namespace App\Http\Controllers;

use App\Enums\Permiso;
use App\Models\PaymentProof;
use App\Support\Auditor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Entrega un comprobante desde el disco privado.
 *
 * Los archivos nunca se exponen por URL pública: cada descarga pasa por aquí,
 * verifica el permiso y queda registrada. El rol Acreditación no tiene acceso.
 */
class DescargarComprobanteController extends Controller
{
    public function __invoke(Request $request, PaymentProof $proof): StreamedResponse
    {
        abort_unless(
            $request->user()?->can(Permiso::VerComprobantes->value),
            403,
            'No tienes permiso para ver comprobantes.',
        );

        abort_unless($proof->existe(), 404, 'El archivo ya no está disponible.');

        // Con `ver` se muestra dentro de la ficha, junto a los datos del pago,
        // para revisar sin descargar ni cambiar de ventana. Queda auditado igual.
        $enPantalla = $request->boolean('ver');

        Auditor::registrar(
            sobre: $proof->payment->order,
            accion: $enPantalla ? 'comprobante.visto' : 'comprobante.descargado',
            propiedades: ['comprobante_id' => $proof->getKey(), 'archivo' => $proof->original_name],
        );

        $disco = Storage::disk($proof->disk);

        return $enPantalla
            ? $disco->response($proof->path, $proof->original_name, [], 'inline')
            : $disco->download($proof->path, $proof->original_name);
    }
}
