<x-mail::message>
# Nueva solicitud de programa

@include('mail._aviso', [
    'tono' => 'alerta',
    'mensaje' => '<strong>'.e($solicitud->nombre_completo).'</strong> pidió el programa de <strong>'.e($evento->name).'</strong>.',
])

<x-mail::panel>
**Datos de contacto:**<br>
@if ($solicitud->institution)
**Institución:** {{ $solicitud->institution }}<br>
@endif
@if ($solicitud->position)
**Cargo:** {{ $solicitud->position }}<br>
@endif
@if ($solicitud->phone)
**Teléfono:** {{ $solicitud->phone }}<br>
@endif
**Correo:** {{ $solicitud->email }}<br>
**Recibida:** {{ \App\Support\Presentar::fechaYHora($solicitud->created_at) }}
</x-mail::panel>

<x-mail::button :url="$url">
Ver solicitud
</x-mail::button>
</x-mail::message>
