<x-mail::message>
@include('mail._banner', ['evento' => $event])

# Inscripción confirmada en {{ $ordenes->count() }} establecimientos

Hola {{ $responsable->responsible_name }},

Recibimos tu inscripción para el **{{ $event->name }}** en estos establecimientos.
Cada uno tiene su propio pago, su propia factura y sus propios cupos reservados.

@include('mail._pasos', ['actual' => 3])

<x-mail::table>
| Establecimiento | N° de orden | Participantes | Total | Reservado hasta |
|:--|:--|--:|--:|:--|
@foreach ($ordenes as $orden)
| {{ $orden->establishments->first()?->name ?: '—' }} | {{ $orden->number }} | {{ $orden->participantesVigentes->count() }} | ${{ number_format($orden->total, 0, ',', '.') }} | {{ $orden->reserved_until?->translatedFormat('d-m-Y H:i') }} |
@endforeach
</x-mail::table>

**Total a pagar:** ${{ number_format($total, 0, ',', '.') }}

## Instrucciones de pago

Cada establecimiento se paga y se factura por separado. Usa el número de cada
inscripción (de la tabla de arriba) como referencia de su propia transferencia.

@if ($event->bank_account_number)
<x-mail::panel>
**Titular**<br>
{{ $event->bank_holder_name }}<br><br>
**RUT**<br>
{{ $event->bank_holder_rut }}<br><br>
**Banco**<br>
{{ $event->bank_name }}<br><br>
**Tipo de cuenta**<br>
{{ $event->bank_account_type }}<br><br>
**N° de cuenta**<br>
{{ $event->bank_account_number }}<br><br>
**Correo para el comprobante**<br>
{{ $event->bank_email }}
</x-mail::panel>
@else
Nos pondremos en contacto contigo con los datos para realizar la transferencia.
@endif

@if ($event->payment_instructions)
{{ $event->payment_instructions }}
@endif

Desde aquí ves el detalle de cada establecimiento y subes el comprobante de cada uno:

<x-mail::button :url="$urlSeguimiento">
Ver mis inscripciones
</x-mail::button>

@include('mail._contacto', ['evento' => $event])

Saludos,<br>
{{ config('app.name') }}
</x-mail::message>
