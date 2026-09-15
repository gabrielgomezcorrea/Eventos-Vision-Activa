<?php

namespace App\Http\Requests\Comprobantes;

use App\Enums\PaymentReviewAction;
use App\Models\Payment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Aprobar, observar o rechazar. El comentario es obligatorio para observar y
 * rechazar: sin motivo, al cliente le llega un correo que no dice qué corregir.
 */
class RevisarComprobanteRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Payment $pago */
        $pago = $this->route('payment');

        return $this->user()?->can('update', $pago) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // El comentario es obligatorio siempre, no solo al observar o rechazar.
        // Es lo que queda en la auditoría y lo que explica, meses después, por
        // qué esta orden se aprobó y aquella no.
        return [
            'decision' => ['required', Rule::enum(PaymentReviewAction::class)],
            'comment' => ['required', 'string', 'min:6', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $motivo = match ($this->decision()) {
            PaymentReviewAction::Observar => 'Indica qué debe corregir el cliente.',
            PaymentReviewAction::Rechazar => 'Indica el motivo del rechazo.',
            default => 'Escribe una nota de la revisión.',
        };

        return [
            'decision.required' => 'Elige qué corresponde hacer con el comprobante.',
            'comment.required' => $motivo,
            'comment.min' => $motivo.' Con unas pocas palabras basta, pero que se entienda.',
        ];
    }

    public function decision(): ?PaymentReviewAction
    {
        return PaymentReviewAction::tryFrom((string) $this->input('decision'));
    }
}
