<?php

namespace App\Http\Controllers\Solicitudes;

use App\Actions\ExportarContactos;
use App\Enums\PaymentStatus;
use App\Enums\Permiso;
use App\Http\Controllers\Controller;
use App\Support\Auditor;
use App\Support\Forms\ProgramFormField;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Descarga de contactos en CSV para limpiar e importar en Brevo o el CRM.
 */
class ExportarContactosController extends Controller
{
    public function __invoke(Request $request, ExportarContactos $exportar): StreamedResponse
    {
        $filtros = $this->filtros($request);

        $filas = $exportar($filtros);

        Auditor::registrar(
            sobre: $request->user(),
            accion: 'contactos.exportados',
            propiedades: ['filtros' => $filtros, 'cantidad' => count($filas)],
        );

        return response()->streamDownload(function () use ($filas): void {
            $salida = fopen('php://output', 'w');

            if ($salida === false) {
                return;
            }

            // BOM: sin él Excel abre el UTF-8 como Latin-1 y rompe tildes y ñ.
            fwrite($salida, "\xEF\xBB\xBF");
            // Punto y coma: es el separador que Excel espera con configuración regional de Chile.
            fputcsv($salida, ExportarContactos::COLUMNAS, ';');

            foreach ($filas as $fila) {
                fputcsv($salida, array_values($fila), ';');
            }

            fclose($salida);
        }, 'contactos-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** How many contacts the current filters would export, shown live in the panel. */
    public function cantidad(Request $request, ExportarContactos $exportar): JsonResponse
    {
        return response()->json(['cantidad' => count($exportar($this->filtros($request)))]);
    }

    /**
     * @return array{evento: int|null, cargo?: string|null, tipos?: array<int, string>, pago?: string|null, marketing: bool}
     */
    private function filtros(Request $request): array
    {
        abort_unless($request->user()?->can(Permiso::ExportarContactos->value), 403);

        $filtros = $request->validate([
            'evento' => ['nullable', 'integer', 'exists:events,id'],
            'cargo' => ['nullable', 'string', Rule::in(ProgramFormField::CARGOS)],
            'tipos' => ['nullable', 'array'],
            'tipos.*' => ['string', Rule::in(array_keys(ExportarContactos::TIPOS))],
            'pago' => ['nullable', Rule::enum(PaymentStatus::class)],
            'marketing' => ['nullable', 'boolean'],
        ]);
        $filtros['marketing'] = $request->boolean('marketing');
        $filtros['evento'] = isset($filtros['evento']) ? (int) $filtros['evento'] : null;

        return $filtros;
    }
}
