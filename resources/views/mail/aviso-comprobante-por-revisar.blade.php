<x-mail::message>
@php $credito = $pago->method === \App\Enums\PaymentMethod::Credito; @endphp
# {{ $credito ? 'Pago a crédito por aprobar' : 'Comprobante por revisar' }}

@include('mail._aviso', [
    'tono' => 'alerta',
    'mensaje' => $credito
        ? '<strong>'.e($colegio).'</strong> eligió pagar a crédito por <strong>$'.number_format($pago->amount, 0, ',', '.').'</strong>. No adjunta comprobante: solo falta aprobarlo.'
        : '<strong>'.e($colegio).'</strong> subió un comprobante por <strong>$'.number_format($pago->amount, 0, ',', '.').'</strong>.',
])

<x-mail::panel>
**Inscripción:** {{ $pago->order->number }}<br>
**Evento:** {{ $pago->order->event->name }}<br>
**Monto:** ${{ number_format($pago->amount, 0, ',', '.') }}<br>
**Forma de pago:** {{ $pago->method->label() }}<br>
@if ($pago->purchase_order)
**Orden de compra:** {{ $pago->purchase_order }}<br>
@endif
@if ($pago->paid_on)
**Transferido el:** {{ \App\Support\Presentar::fecha($pago->paid_on) }}<br>
@endif
@if ($pago->bank_name)
**Banco:** {{ $pago->bank_name }}<br>
@endif
@if ($pago->payer_name)
**Pagador:** {{ $pago->payer_name }}<br>
@endif
**Saldo de la inscripción:** ${{ number_format($pago->order->saldo(), 0, ',', '.') }}
</x-mail::panel>

<x-mail::button :url="$url">
{{ $credito ? 'Revisar pago a crédito' : 'Revisar comprobante' }}
</x-mail::button>
</x-mail::message>
