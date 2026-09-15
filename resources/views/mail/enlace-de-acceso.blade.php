<x-mail::message>
# {{ $event->name }}

@include('mail._pasos', ['actual' => 2])

@if ($numeroDeOrden)
Continúa con tu inscripción **{{ $numeroDeOrden }}** desde este enlace:
@else
Completa tu inscripción desde este enlace. Puedes cerrarlo y retomarlo más tarde:
tus datos quedan guardados.
@endif

<x-mail::button :url="$url">
{{ $numeroDeOrden ? 'Continuar inscripción' : 'Iniciar inscripción' }}
</x-mail::button>

El enlace es personal y vence en {{ (int) round(config('magic_links.ttl_minutes') / 1440) }} días. Si expira, puedes pedir uno nuevo con tu correo.

@include('mail._contacto', ['evento' => $event])

Saludos,<br>
{{ config('app.name') }}
</x-mail::message>
