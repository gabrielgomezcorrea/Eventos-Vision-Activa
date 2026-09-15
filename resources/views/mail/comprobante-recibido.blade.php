<x-mail::message>
# Comprobante recibido

Hola {{ $orden->responsible_name }},

@include('mail._pasos', ['actual' => 3])

Recibimos tu comprobante de la inscripción **{{ $orden->number }}** para el {{ $event->name }}.
El pago se encuentra en proceso de validación.

Las credenciales con código QR se liberan una vez que Contabilidad confirme el abono.
Te avisaremos por este mismo medio.

<x-mail::panel>
**Inscripción:** {{ $orden->number }}<br>
**Monto informado:** ${{ number_format($orden->pagoVigente()?->amount ?? 0, 0, ',', '.') }}<br>
**Estado:** {{ $orden->payment_status->label() }}
</x-mail::panel>

Mientras revisamos, tus cupos siguen reservados.

<x-mail::button :url="$urlEstado">
Ver el estado de mi inscripción
</x-mail::button>

@include('mail._contacto', ['evento' => $event])

Saludos,<br>
{{ config('app.name') }}
</x-mail::message>
