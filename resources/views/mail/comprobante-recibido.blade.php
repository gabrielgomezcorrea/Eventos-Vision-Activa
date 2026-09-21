<x-mail::message>
@include('mail._banner', ['evento' => $event])

# Comprobante recibido

Hola {{ $orden->responsible_name }},

Recibimos el comprobante de pago de tu inscripción **{{ $orden->number }}** para
{{ $event->name }}. Muchas gracias.

Contabilidad está revisando tu comprobante; mientras tanto, tus cupos siguen
reservados. Cuando el pago quede validado, cada participante recibirá su
credencial con código QR por correo y te avisaremos por este mismo medio.

Estos son los pasos:

@include('mail._pasos', ['actual' => 3])

<x-mail::panel>
**Inscripción:** {{ $orden->number }}<br>
**Monto informado:** ${{ number_format($orden->pagoVigente()?->amount ?? 0, 0, ',', '.') }}<br>
**Estado:** {{ $orden->payment_status->label() }}
</x-mail::panel>

<x-mail::button :url="$urlEstado">
Ver el estado de mi inscripción
</x-mail::button>

@include('mail._contacto', ['evento' => $event])

Saludos,<br>
{{ config('app.name') }}
</x-mail::message>
