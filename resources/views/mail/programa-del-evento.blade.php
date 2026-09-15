{{--
    Primer correo. No lleva el recorrido de pasos a propósito: quien acaba de
    pedir el programa todavía no está en un trámite, y mostrarle cuatro etapas
    con transferencias y credenciales lo confunde antes de empezar. Acá solo
    importa que vea el evento y entre a inscribirse. Los pasos aparecen desde el
    correo siguiente, cuando ya está dentro.
--}}
<x-mail::message>
# {{ $event->name }}

Hola {{ $solicitud->first_name }},

@if ($event->program_email_intro)
{{ $event->program_email_intro }}
@else
Gracias por tu interés.@if ($event->attachments->isNotEmpty()) Adjuntamos la información en este correo.@endif
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

<x-mail::button :url="route('inscripcion.inicio', $event)">
Inscribirme
</x-mail::button>

Entra con tu correo. No necesitas contraseña y puedes retomar cuando quieras.

@include('mail._contacto', ['evento' => $event])

Saludos,<br>
{{ config('app.name') }}
</x-mail::message>
