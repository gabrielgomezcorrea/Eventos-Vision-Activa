<?php

namespace App\Http\Controllers;

use App\Enums\Permiso;
use App\Models\InvoiceRecord;
use App\Support\Auditor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Opens the invoice or receipt file registered on an order, from the private
 * disk, only for whoever manages invoices. Each opening is audited, like proofs.
 * Inline: the browser shows PDFs and images and downloads anything else.
 */
class DescargarFacturaController extends Controller
{
    public function __invoke(Request $request, InvoiceRecord $invoiceRecord): StreamedResponse
    {
        abort_unless($request->user()?->can(Permiso::GestionarFacturas->value), 403);
        abort_unless($invoiceRecord->tieneArchivo(), 404, 'El archivo ya no está disponible.');

        Auditor::registrar(
            sobre: $invoiceRecord->order,
            accion: 'factura.vista',
            propiedades: ['factura_id' => $invoiceRecord->getKey(), 'documento' => $invoiceRecord->descripcion()],
        );

        return Storage::disk($invoiceRecord->disk ?: config('filesystems.private_disk'))
            ->response($invoiceRecord->path, $invoiceRecord->original_name ?: $invoiceRecord->descripcion(), [], 'inline');
    }
}
