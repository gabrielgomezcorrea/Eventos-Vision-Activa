<x-mail::message>
@include('mail._banner', ['evento' => $event])

# Abono recibido

Hola {{ $orden->responsible_name }},

Contabilidad aprobó tu abono de **${{ number_format($abono->amount, 0, ',', '.') }}** para la
inscripción **{{ $orden->number }}** de {{ $event->name }}. Muchas gracias.

@include('mail._pasos', ['actual' => 3])

Queda un saldo de **${{ number_format($orden->saldo(), 0, ',', '.') }}**. Puedes pagarlo en una o
varias transferencias y subir cada comprobante desde tu inscripción. Las credenciales con código QR
se envían cuando el pago está completo.

<x-mail::panel>
**Inscripción:** {{ $orden->number }}<br>
**Total:** ${{ number_format($orden->total, 0, ',', '.') }}<br>
**Pagado:** ${{ number_format($orden->pagado(), 0, ',', '.') }}<br>
**Saldo:** ${{ number_format($orden->saldo(), 0, ',', '.') }}
</x-mail::panel>

<x-mail::button :url="$urlEstado">
Subir el siguiente comprobante
</x-mail::button>

@include('mail._contacto', ['evento' => $event])

Saludos,<br>
{{ config('app.name') }}
</x-mail::message>
