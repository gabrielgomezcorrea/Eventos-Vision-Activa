<x-mail::message>
@include('mail._banner', ['evento' => $event])

# {{ $event->name }}

Hola,

Te invitamos a participar en el **{{ $event->name }}**@if ($event->starts_on), el {{ $event->starts_on->translatedFormat('d \d\e F \d\e Y') }}@endif.
Tu entrada no tiene costo: completa tus datos desde el enlace y te enviaremos tu credencial.

<x-mail::button :url="$url">
Completar mi inscripción
</x-mail::button>

El enlace es personal, se puede usar una sola vez y vale hasta el {{ $invitacion->expires_at->translatedFormat('d \d\e F \d\e Y') }}.

@include('mail._contacto', ['evento' => $event])

Saludos,<br>
{{ config('app.name') }}
</x-mail::message>
