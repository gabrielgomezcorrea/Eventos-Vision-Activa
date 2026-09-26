@extends('publico.layout')
@section('titulo', 'Invitación')
@section('subtitulo', 'Completa tus datos para recibir tu credencial')

@section('contenido')
    <form method="POST" action="{{ route('publico.invitacion.guardar', ['token' => $token]) }}" novalidate>
        <div class="tarjeta">
            <h2>Tus datos</h2>
            <p class="sub">Tu entrada no tiene costo. Enviaremos tu credencial a {{ $invitacion->email }}.</p>

            <div class="rejilla">
                @include('publico.inscripcion._campo', ['campo' => 'first_name', 'etiqueta' => 'Nombre', 'autocomplete' => 'given-name'])
                @include('publico.inscripcion._campo', ['campo' => 'last_name', 'etiqueta' => 'Apellidos', 'autocomplete' => 'family-name'])
                @include('publico.inscripcion._campo', ['campo' => 'rut', 'etiqueta' => 'RUT', 'placeholder' => '12.345.678-9', 'atributos' => 'data-rut maxlength="12" autocomplete="off"'])
                @include('publico.inscripcion._campo', ['campo' => 'phone', 'etiqueta' => 'Teléfono', 'tipoCampo' => 'tel', 'placeholder' => '56912345678', 'opcional' => true, 'autocomplete' => 'tel'])
                @include('publico.inscripcion._cargo', ['campo' => 'position', 'opciones' => $event->cargosDeParticipante()])
                @include('publico.inscripcion._campo', ['campo' => 'establecimiento', 'etiqueta' => 'Establecimiento o institución', 'opcional' => true, 'ancho' => true])
            </div>
        </div>

        @if ($errorDeCupo)
            <div class="aviso aviso-error">{{ $errorDeCupo }}</div>
        @endif

        <div class="acciones">
            <button type="submit" class="btn btn-primario" data-enviando-texto="Enviando…">Recibir mi credencial</button>
        </div>
    </form>
@endsection
