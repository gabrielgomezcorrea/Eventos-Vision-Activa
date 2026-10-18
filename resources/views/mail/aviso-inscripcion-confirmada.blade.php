<x-mail::message>
# Nueva inscripción confirmada

@if ($ordenes->count() === 1)
@include('mail._aviso', [
    'tono' => 'exito',
    'mensaje' => '<strong>'.e($orden->nombreDelColegio() ?? $orden->responsableNombreCompleto()).'</strong> confirmó su inscripción en <strong>'.e($orden->event->name).'</strong>.',
])

<x-mail::panel>
**Inscripción:** {{ $orden->number }}<br>
**Participantes:** {{ $orden->participantesVigentes()->count() }}<br>
**Total:** ${{ number_format($orden->total, 0, ',', '.') }}<br>
**Responsable:** {{ $orden->responsableNombreCompleto() }}@if ($orden->responsible_phone) · {{ $orden->responsible_phone }}@endif<br>
**Correo:** {{ $orden->responsible_email }}
</x-mail::panel>
@else
@include('mail._aviso', [
    'tono' => 'exito',
    'mensaje' => 'Una misma persona confirmó <strong>'.$ordenes->count().' colegios</strong> en <strong>'.e($orden->event->name).'</strong>.',
])

<x-mail::panel>
@foreach ($ordenes as $o)
**{{ $o->nombreDelColegio() ?? $o->responsableNombreCompleto() }}**<br>
{{ $o->number }} · {{ $o->participantesVigentes()->count() }} participante(s) · ${{ number_format($o->total, 0, ',', '.') }}<br><br>
@endforeach
**Responsable:** {{ $orden->responsableNombreCompleto() }}@if ($orden->responsible_phone) · {{ $orden->responsible_phone }}@endif<br>
**Correo:** {{ $orden->responsible_email }}
</x-mail::panel>
@endif

<x-mail::button :url="$url">
{{ $ordenes->count() === 1 ? 'Ver inscripción' : 'Ver inscripciones' }}
</x-mail::button>
</x-mail::message>
