{{--
    COPIA de programa-del-evento con las dos opciones de inscripción. No está
    conectada: el texto de las opciones (código, valores, secretaria, correo)
    está escrito a mano tal como lo pidió Gabriel y habría que sacarlo de los
    datos del evento antes de usarla.

    Desde «Puedes participar…» hasta el final el texto está escrito a mano, tal
    como lo pidió Gabriel (05/11/2026). La parte de arriba (saludo, pasos y datos
    del evento) es la del sistema.
--}}
<x-mail::message>
@include('mail._banner', ['evento' => $event])

# {{ $event->name }}

Hola{{ $solicitud->first_name ? ' '.$solicitud->first_name : '' }},

@if ($event->program_email_intro)
{{ $event->program_email_intro }}
@else
Gracias por tu interés en **{{ $event->name }}**.@if ($event->attachments->isNotEmpty()) Adjuntamos {{ $event->attachments->count() > 1 ? 'los documentos' : 'el documento' }} del evento en este correo.@endif
@endif

Estos son los pasos:

@include('mail._pasos', ['actual' => 2])

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

Puedes participar en una jornada o en ambas:

- **Miércoles 11:** Conducir la mejora sin perder el rumbo.
- **Jueves 12:** Convertir la estrategia en mejora real.

## Tu código de descuento: CMEI-2026

<x-mail::table>
| Participación | Valor regular | Valor con descuento |
|:--|--:|--:|
| Una jornada, a elección | $120.000 | **$80.000** |
| Ambas jornadas | $180.000 | **$140.000** |
</x-mail::table>

Valores por persona.

## ¿Cómo inscribirse? Elige una de estas dos opciones

### 1. Inscripción en línea

Completa tú mismo la inscripción:

1. Presiona el botón «Completar inscripción en línea».
2. Ingresa los datos de facturación y de cada participante. Selecciona la jornada o ambas jornadas.
3. Escribe el código **CMEI-2026** y revisa que el descuento esté aplicado antes de transferir.
4. Realiza la transferencia y sube el comprobante a la plataforma.

Cuando validemos el pago, enviaremos las credenciales con código QR.

<x-mail::button :url="$enlace">
Completar inscripción en línea
</x-mail::button>

**¿Lo hará otra persona?** Reenvíale este correo o copia este enlace:

<p style="word-break:break-all;overflow-wrap:anywhere;font-size:15px;margin:0 0 16px"><a href="{{ $enlace }}">{{ $enlace }}</a></p>

### 2. Inscripción asistida por nuestra secretaria

Si prefieres que te ayudemos o tu establecimiento necesita gestionar el pago contra factura:

1. Completa la ficha de inscripción adjunta con los datos del establecimiento y de los participantes.
2. Envíala a administracion@visionactiva.cl e indica el código **CMEI-2026** para solicitar los valores señalados.

Flor Vidal Oliva, nuestra secretaria, ingresará la inscripción en la plataforma y coordinará contigo la facturación y el pago. Si el establecimiento requiere pago contra factura, ella te ayudará a gestionar esa modalidad.

Las credenciales QR se enviarán una vez confirmado el pago o aceptada la compra institucional.

<x-mail::panel>
**Importante:** recibir el programa no significa estar inscrito. Para participar, debes completar una de las dos alternativas anteriores.
</x-mail::panel>

Saludos cordiales,<br>
{{ config('app.name') }}

{{-- Pie común: «¿Tienes dudas? Contáctanos» con los datos del evento y el aviso de no responder. --}}
@include('mail._contacto', ['evento' => $event])
</x-mail::message>
