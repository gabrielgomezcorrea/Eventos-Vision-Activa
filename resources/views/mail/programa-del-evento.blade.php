{{--
    Primer correo. No lleva el recuadro de pasos: quien acaba de pedir el
    programa todavía no está en un trámite. Sí lleva, por pedido de la reunión
    del 17/09/2026, el proceso de inscripción y pago contado en pocas líneas,
    para que sepa qué viene antes de entrar.
--}}
<x-mail::message>
@include('mail._banner', ['evento' => $event])

# {{ $event->name }}

Hola {{ $solicitud->first_name }},

@if ($event->program_email_intro)
{{ $event->program_email_intro }}
@else
Gracias por tu interés en **{{ $event->name }}**.@if ($event->attachments->isNotEmpty()) Adjuntamos el programa del evento en este correo.@endif
@endif

<x-mail::panel>
@if ($event->starts_on)
**Fecha:** {{ $event->starts_on->translatedFormat('l d \d\e F \d\e Y') }}<br>
@endif
@if ($event->location)
**Lugar:** {{ $event->location }}@if ($event->city), {{ $event->city }}@endif<br>
@endif
@if ($solicitud->interest_label)
**Jornada de tu interés:** {{ $solicitud->interest_label }}<br>
@endif
@foreach ($event->sessions as $jornada)
**{{ $jornada->name }}:** @if ($jornada->starts_at){{ $jornada->starts_at->translatedFormat('d \d\e F, H:i') }}@else por confirmar @endif<br>
@endforeach
</x-mail::panel>

**Así te inscribes en línea:**

1. Entras con tu correo desde el botón de abajo, sin contraseña.
2. Completas tus datos, los del establecimiento y los participantes.
3. Transfieres el valor de la inscripción y subes el comprobante.
4. Cuando validamos el pago, cada participante recibe su credencial con código QR.

<x-mail::button :url="route('inscripcion.inicio', $event)">
Inscribirme
</x-mail::button>

Entra con tu correo. No necesitas contraseña y puedes retomar cuando quieras.

@include('mail._contacto', ['evento' => $event])

Saludos,<br>
{{ config('app.name') }}
</x-mail::message>
