<x-mail::message>
# {{ $factura->descripcion() }}

Hola {{ $orden->responsible_name }},

Emitimos el documento tributario de tu inscripción **{{ $orden->number }}** para el
{{ $event->name }}.

<x-mail::panel>
**Documento:** {{ $factura->descripcion() }}<br>
**Fecha de emisión:** {{ $factura->issued_on?->translatedFormat('d \d\e F \d\e Y') }}<br>
**Monto:** ${{ number_format($factura->amount, 0, ',', '.') }}<br>
**Inscripción:** {{ $orden->number }}
</x-mail::panel>

@if ($factura->tieneArchivo())
Lo adjuntamos en este correo. También queda disponible en tu inscripción:
@else
Puedes revisar el detalle de tu inscripción acá:
@endif

<x-mail::button :url="$urlEstado">
Ver mi inscripción
</x-mail::button>

@include('mail._contacto', ['evento' => $event])

Saludos,<br>
{{ config('app.name') }}
</x-mail::message>
