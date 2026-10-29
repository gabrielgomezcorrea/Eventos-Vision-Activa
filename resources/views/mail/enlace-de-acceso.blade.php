<x-mail::message>
@include('mail._banner', ['evento' => $event])

# {{ $event->name }}

Hola{{ $nombre ? " {$nombre}" : '' }},

Estás en el proceso de reserva de tu inscripción. Completa la reserva desde el
enlace de este correo: tus cupos quedan reservados al confirmarla.

Estos son los pasos:

@include('mail._pasos', ['actual' => 2])

@if ($colegios && $colegios->count() > 1)
Tienes {{ $colegios->count() }} inscripciones confirmadas para este evento:

<x-mail::table>
| Establecimiento | N° de orden |
|:--|:--|
@foreach ($colegios as $colegio)
| {{ $colegio->establishments->first()?->name ?: '—' }} | {{ $colegio->number }} |
@endforeach
</x-mail::table>

Desde este enlace ves el detalle de cada una y subes el comprobante que falte:
@elseif ($numeroDeOrden)
Continúa con tu inscripción **{{ $numeroDeOrden }}** desde este enlace:
@else
Completa tu inscripción desde este enlace. Puedes cerrarlo y retomarlo más tarde:
tus datos quedan guardados.
@endif

<x-mail::button :url="$url">
{{ $colegios && $colegios->count() > 1 ? 'Ver mis inscripciones' : ($numeroDeOrden ? 'Continuar mi inscripción' : 'Completar mi inscripción') }}
</x-mail::button>

**También puedes reenviar este correo a tu encargado de inscribir para que continúe el proceso.**

**O puedes copiar el siguiente enlace a la persona encargada de realizar el proceso:**

<p style="word-break:break-all;overflow-wrap:anywhere;font-size:15px;margin:0 0 16px"><a href="{{ $url }}">{{ $url }}</a></p>

@if ($event->cierreDeEnlaces())
El enlace sirve hasta el {{ $event->cierreDeEnlaces()->translatedFormat('d \d\e F \d\e Y') }}. Si expira, puedes pedir uno nuevo con tu correo.
@else
El enlace sirve por {{ (int) round(config('magic_links.ttl_minutes') / 1440) }} días. Si expira, puedes pedir uno nuevo con tu correo.
@endif

@include('mail._contacto', ['evento' => $event])

Saludos,<br>
{{ config('app.name') }}
</x-mail::message>
