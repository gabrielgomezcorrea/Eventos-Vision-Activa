<x-mail::message>
@include('mail._banner', ['evento' => $event])

# Tu reserva vence pronto

Hola {{ $orden->responsible_name }},

@include('mail._pasos', ['actual' => 3])

Los cupos de tu inscripción **{{ $orden->number }}** para el {{ $event->name }} están reservados
hasta el **{{ $orden->reserved_until?->translatedFormat('l d \d\e F \d\e Y, H:i') }}**.

Si no recibimos el pago antes de esa fecha, los cupos se liberan.

<x-mail::panel>
**Monto pendiente:** ${{ number_format($orden->total, 0, ',', '.') }}<br>
**Participantes:** {{ $orden->participantesVigentes->count() }}<br>
**Referencia de la transferencia:** {{ $orden->number }}
</x-mail::panel>

@if ($event->bank_account_number)
<x-mail::table>
| | |
|:--|:--|
| Titular | {{ $event->bank_holder_name }} |
| Banco | {{ $event->bank_name }} |
| N° de cuenta | {{ $event->bank_account_number }} |
</x-mail::table>
@endif

Si ya transferiste, sube el comprobante desde tu inscripción y la reserva se mantiene
mientras Contabilidad lo revisa.

<x-mail::button :url="$urlEstado">
Subir el comprobante
</x-mail::button>

@if ($event->contact_email || $event->contact_phone)
¿Necesitas más plazo? Contáctanos:

@include('mail._datos_contacto', ['evento' => $event])
@endif

@include('mail._contacto', ['evento' => $event])

Saludos,<br>
{{ config('app.name') }}
</x-mail::message>
