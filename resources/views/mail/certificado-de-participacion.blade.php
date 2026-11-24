{{--
    Gabriel's text (24/11/2026), literal: only the bracketed parts change
    (event, participant, dates). Do not rewrite it.
--}}
<x-mail::message>
@include('mail._banner', ['evento' => $evento])

Estimado/a {{ $participante?->nombre_completo }},

Reciba un cordial saludo de parte del equipo organizador de **{{ $evento->name }}**.

Queremos agradecerle sinceramente por su asistencia y valiosa participación durante la jornada realizada{{ $evento->fechasLegibles() ? ' el pasado '.$evento->fechasLegibles() : '' }}. Su presencia contribuyó significativamente al éxito de este encuentro.

Tal como lo prometimos, adjunto a este correo encontrará su **Certificado Oficial de Participación** en formato PDF.

Nuevamente, muchas gracias por acompañarnos.

Atentamente,<br>
{{ config('app.name') }}

@include('mail._contacto', ['evento' => $evento])
</x-mail::message>
