<x-mail::message>
# Comprobante por revisar

@include('mail._aviso', [
    'tono' => 'alerta',
    'mensaje' => '<strong>'.e($colegio).'</strong> subió un comprobante por <strong>$'.number_format($pago->amount, 0, ',', '.').'</strong>.',
])

<x-mail::panel>
**Inscripción:** {{ $pago->order->number }}<br>
**Evento:** {{ $pago->order->event->name }}<br>
**Monto:** ${{ number_format($pago->amount, 0, ',', '.') }}<br>
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
Revisar comprobante
</x-mail::button>
</x-mail::message>
