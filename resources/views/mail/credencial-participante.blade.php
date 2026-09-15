<x-mail::message>
# Tu credencial de acceso

Hola {{ $participante->first_name }},

@include('mail._pasos', ['actual' => 4])

Tu inscripción a **{{ $evento->name }}** está confirmada. Esta es tu credencial de acceso.

<x-mail::panel>
**Participante:** {{ $participante->nombre_completo }}
@if ($participante->establishment?->name)
**Establecimiento:** {{ $participante->establishment->name }}
@endif
**Acceso:** {{ $participante->accessType?->name }}<br>
**Código de respaldo:** {{ $ticket->code }}
</x-mail::panel>

<x-mail::button :url="$ticket->url()">
Ver mi credencial
</x-mail::button>

Puedes presentarla desde tu teléfono o impresa. Si el código QR no se puede leer, indica en
el ingreso tu código de respaldo: **{{ $ticket->code }}**.

La acreditación se realiza una sola vez, en tu primer ingreso al evento.

@include('mail._contacto', ['evento' => $evento])

Saludos,<br>
{{ config('app.name') }}
</x-mail::message>
