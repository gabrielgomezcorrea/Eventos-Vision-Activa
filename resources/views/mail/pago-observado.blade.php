<x-mail::message>
# {{ Str::of($observacion)->before('.')->trim() }}

Hola {{ $orden->responsible_name }},

@include('mail._pasos', ['actual' => 3])

Revisamos el comprobante de tu inscripción **{{ $orden->number }}** para el
{{ $event->name }} y hay algo que corregir:

@include('mail._aviso', ['tono' => 'alerta', 'mensaje' => e($observacion)])

**Tus cupos siguen reservados.** Envía un comprobante corregido desde tu inscripción y
seguimos con la validación.

<x-mail::button :url="$urlEstado">
Enviar un comprobante corregido
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
