<x-mail::message>
# Inscripción {{ $orden->number }} confirmada

Hola {{ $orden->responsible_name }},

Recibimos tu inscripción **{{ $orden->number }}** para el **{{ $event->name }}**.
Los cupos quedan reservados hasta el
**{{ $orden->reserved_until?->translatedFormat('l d \d\e F \d\e Y, H:i') }}**.

@include('mail._pasos', ['actual' => 3])

<x-mail::panel>
**Número de inscripción:** {{ $orden->number }}<br>
**Entidad pagadora:** {{ $orden->payerEntity?->name }}<br>
**Participantes:** {{ $orden->participantesVigentes->count() }}<br>
@if ($orden->discount_amount > 0)
**Subtotal:** ${{ number_format($orden->subtotal, 0, ',', '.') }}<br>
**Descuento:** -${{ number_format($orden->discount_amount, 0, ',', '.') }} ({{ $orden->discount_label }})<br>
@endif
**Monto total:** ${{ number_format($orden->total, 0, ',', '.') }}
</x-mail::panel>

## Participantes registrados

<x-mail::table>
| Participante | Acceso | Valor |
|:--|:--|--:|
@foreach ($orden->participantesVigentes as $p)
| {{ $p->nombre_completo }} | {{ $p->accessType?->name }} | ${{ number_format($p->unit_price, 0, ',', '.') }} |
@endforeach
</x-mail::table>

## Instrucciones de pago

@if ($event->bank_account_number)
Transfiere ${{ number_format($orden->total, 0, ',', '.') }} a esta cuenta:

<x-mail::panel>
**Titular**<br>
{{ $event->bank_holder_name }}<br><br>
**RUT**<br>
{{ $event->bank_holder_rut }}<br><br>
**Banco**<br>
{{ $event->bank_name }}<br><br>
**Tipo de cuenta**<br>
{{ $event->bank_account_type }}<br><br>
**N° de cuenta**<br>
{{ $event->bank_account_number }}<br><br>
**Correo para el comprobante**<br>
{{ $event->bank_email }}<br><br>
**Mensaje de la transferencia**<br>
Inscripción {{ $orden->number }}
</x-mail::panel>
@else
Nos pondremos en contacto contigo con los datos para realizar la transferencia.
@endif

@if ($event->payment_instructions)
{{ $event->payment_instructions }}
@endif

Luego de transferir, sube el comprobante acá:

<x-mail::button :url="$urlSeguimiento">
Subir el comprobante
</x-mail::button>

@include('mail._contacto', ['evento' => $event])

Saludos,<br>
{{ config('app.name') }}
</x-mail::message>
