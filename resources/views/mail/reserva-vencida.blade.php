<x-mail::message>
@include('mail._banner', ['evento' => $event])

# Reserva vencida

Hola {{ $orden->responsible_name }},

@include('mail._pasos', ['actual' => 3])

El plazo de pago de tu inscripción **{{ $orden->number }}** para el {{ $event->name }} venció y los
cupos quedaron liberados.

<x-mail::panel>
**Inscripción:** {{ $orden->number }}<br>
**Estado:** {{ $orden->status->label() }}<br>
**Participantes que quedaron sin cupo:** {{ $orden->participantesVigentes->count() }}
</x-mail::panel>

Si aún deseas participar, contáctanos y revisamos la disponibilidad:

@include('mail._datos_contacto', ['evento' => $event])

También puedes iniciar una inscripción nueva desde [la página del evento]({{ route('inscripcion.inicio', $event) }}).

<x-mail::button :url="$urlEstado">
Ver mi inscripción
</x-mail::button>

@include('mail._contacto', ['evento' => $event])

Saludos,<br>
{{ config('app.name') }}
</x-mail::message>
