@extends('publico.layout')
@section('titulo', 'Listo')
@section('subtitulo', 'Tu inscripción quedó registrada')

@section('contenido')
    <div class="tarjeta">
        <h2>¡Listo!</h2>
        <p class="sub">Te enviamos tu credencial a {{ $email }}. Preséntala en la puerta del evento.</p>
        @include('publico._contacto', ['evento' => $event])
    </div>
@endsection
