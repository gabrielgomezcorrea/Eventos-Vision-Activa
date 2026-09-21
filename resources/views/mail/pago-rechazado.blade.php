<x-mail::message>
@include('mail._banner', ['evento' => $event])

# El comprobante no acreditó el pago

Hola {{ $orden->responsible_name }},

@include('mail._pasos', ['actual' => 3])

Revisamos el comprobante de tu inscripción **{{ $orden->number }}** para el {{ $event->name }}
y no fue posible acreditar el pago:

@include('mail._aviso', ['tono' => 'error', 'mensaje' => e($observacion)])

Si crees que se trata de un error, o quieres enviar otro comprobante, puedes hacerlo
desde tu inscripción.

<x-mail::button :url="$urlEstado">
Ver mi inscripción
</x-mail::button>

<x-mail::panel>
**Inscripción:** {{ $orden->number }}<br>
**Estado:** {{ $orden->payment_status->label() }}<br>
**Monto de la inscripción:** ${{ number_format($orden->total, 0, ',', '.') }}
</x-mail::panel>

@include('mail._contacto', ['evento' => $event])

Saludos,<br>
{{ config('app.name') }}
</x-mail::message>
