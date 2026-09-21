{{--
    Goes to the person who attends, not to whoever bought: no purchase steps,
    only their entry. Carries the program attached because the buyer often does
    not forward it (meeting of 17/09/2026).
--}}
<x-mail::message>
@include('mail._banner', ['evento' => $evento])

# Tu credencial de acceso

Hola {{ $participante->first_name }},

Tu inscripción a **{{ $evento->name }}** está confirmada. Esta es tu credencial de acceso.

<x-mail::panel>
**Participante:** {{ $participante->nombre_completo }}<br>
@if ($participante->establishment?->name)
**Establecimiento:** {{ $participante->establishment->name }}<br>
@endif
**Acceso:** {{ $participante->accessType?->name }}<br>
**Código de respaldo:** {{ $ticket->code }}
</x-mail::panel>

<x-mail::button :url="$ticket->url()">
Ver mi credencial
</x-mail::button>

**Presenta tu código QR para ingresar**, desde tu teléfono o impreso. Si no se puede leer,
indica en el ingreso tu código de respaldo: **{{ $ticket->code }}**.

La credencial es personal y **no es transferible** a otra persona.

@if ($evento->attachments->isNotEmpty())
Adjuntamos el programa del evento.
@endif

@include('mail._contacto', ['evento' => $evento])

Saludos,<br>
{{ config('app.name') }}
</x-mail::message>
