<x-mail::message>
@include('mail._banner', ['evento' => $event])

# Tu reserva en {{ $ordenes->count() }} establecimientos vence pronto

Hola {{ $ordenes->first()->responsible_name }},

@include('mail._pasos', ['actual' => 3])

Los cupos de estas inscripciones para el {{ $event->name }} están reservados hasta su
propia fecha. Si no recibimos el pago antes, los cupos se liberan.

<x-mail::table>
| Establecimiento | N° de orden | Saldo | Vence |
|:--|:--|--:|:--|
@foreach ($ordenes as $orden)
| {{ $orden->establishments->first()?->name ?: '—' }} | {{ $orden->number }} | ${{ number_format($orden->saldo(), 0, ',', '.') }} | {{ $orden->reserved_until?->translatedFormat('d-m-Y H:i') }} |
@endforeach
</x-mail::table>

**Total pendiente:** ${{ number_format($total, 0, ',', '.') }}

Si ya transferiste alguno, sube su comprobante desde tu inscripción y esa reserva se
mantiene mientras Contabilidad lo revisa.

<x-mail::button :url="$urlEstado">
Ver mis inscripciones
</x-mail::button>

@if ($event->contact_email || $event->contact_phone)
¿Necesitas más plazo? Contáctanos:

@include('mail._datos_contacto', ['evento' => $event])
@endif

@include('mail._contacto', ['evento' => $event])

Saludos,<br>
{{ config('app.name') }}
</x-mail::message>
