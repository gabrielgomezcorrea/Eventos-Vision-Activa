@extends('publico.layout')
@section('titulo', 'Inscripción')
@section('subtitulo', 'Inicia o retoma tu inscripción')

@section('contenido')
    <div class="tarjeta">
        @if ($enviado)
            <div class="aviso aviso-exito">Enlace enviado.</div>
            <h2>Revisa tu correo</h2>
            <p class="sub">
                Si esa dirección puede inscribirse en este evento, recibirás un enlace para
                iniciar o continuar tu inscripción. No necesitas crear una contraseña.
            </p>
            <p class="sub">Si no lo ves en unos minutos, revisa la carpeta de correo no deseado.</p>
        @else
            <h2>Ingresa tu correo</h2>
            <p class="sub">
                Te enviaremos un enlace seguro. Con él puedes completar la inscripción,
                o retomarla cuando quieras.
            </p>

            <form method="POST" action="{{ route('inscripcion.solicitar', $event) }}" novalidate>
                <div>
                    <label for="email">Correo electrónico</label>
                    <input type="email" id="email" name="email" value="{{ $valores['email'] ?? '' }}"
                           autocomplete="email" @if ($errores->has('email')) aria-invalid="true" @endif>
                    @if ($errores->has('email'))
                        <div class="error-campo">{{ $errores->first('email') }}</div>
                    @endif
                </div>

                <div class="acciones">
                    <button type="submit" class="btn btn-primario" data-enviando-texto="Enviando…">Enviarme el enlace</button>
                </div>
            </form>
        @endif
    </div>
@endsection
