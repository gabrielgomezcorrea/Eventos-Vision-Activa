<x-mail::message>
@include('mail._banner', ['evento' => $event])

# Pago confirmado

Hola {{ $orden->responsible_name }},

@include('mail._pasos', ['actual' => 4])

@include('mail._aviso', [
    'tono' => 'exito',
    'mensaje' => 'Confirmamos el pago de tu inscripción <strong>'.$orden->number.'</strong> para el '
        .e($event->name).'. <strong>Los cupos quedan asegurados.</strong>',
])

<x-mail::panel>
**Inscripción:** {{ $orden->number }}<br>
**Participantes:** {{ $orden->participantesVigentes->count() }}<br>
**Total pagado:** ${{ number_format($orden->total, 0, ',', '.') }}
</x-mail::panel>

## Credenciales de acceso

Las credenciales de tus {{ $orden->participantesVigentes->count() }} participante(s) ya están
disponibles. Puedes verlas todas juntas, imprimirlas o reenviarlas.

<x-mail::button :url="$urlCredenciales">
Ver e imprimir las credenciales
</x-mail::button>

También enviamos su credencial a cada participante que registró un correo.

@include('mail._contacto', ['evento' => $event])

Saludos,<br>
{{ config('app.name') }}
</x-mail::message>
