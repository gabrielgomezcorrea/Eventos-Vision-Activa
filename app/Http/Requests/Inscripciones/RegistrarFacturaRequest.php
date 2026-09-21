<?php

namespace App\Http\Requests\Inscripciones;

use App\Enums\InvoiceDocumentType;
use App\Enums\PaymentStatus;
use App\Enums\Permiso;
use App\Models\Order;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * El sistema no emite documentos tributarios: registra el que se emitió en el
 * sistema contable, y solo cuando el pago ya está aprobado.
 */
class RegistrarFacturaRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Con abonos se factura cada transferencia aprobada, sin esperar al total.
        return ($this->user()?->can(Permiso::GestionarFacturas->value) ?? false)
            && $this->orden()->pagado() > 0;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'document_type' => ['required', Rule::enum(InvoiceDocumentType::class)],
            'number' => ['required', 'string', 'max:50'],
            'issued_on' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'integer', 'min:0'],
            'payment_id' => ['nullable', Rule::in($this->orden()->payments()->where('status', PaymentStatus::Aprobado->value)->pluck('id')->all())],
            'archivo' => ['nullable', 'file', 'mimes:pdf', 'max:10240'],
            // A quién se envía lo decide el sistema: al responsable y a la
            // entidad pagadora, con copia a administración. Escribir el
            // destinatario a mano era la forma de equivocarse.
            'enviar' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'enviar' => 'envío al cliente',
            'document_type' => 'tipo de documento',
            'number' => 'número del documento',
            'issued_on' => 'fecha de emisión',
            'amount' => 'monto',
            'payment_id' => 'abono',
            'archivo' => 'PDF del documento',
            'sent_to' => 'enviada a',
            'notes' => 'observaciones',
        ];
    }

    private function orden(): Order
    {
        /** @var Order */
        return $this->route('order');
    }
}
