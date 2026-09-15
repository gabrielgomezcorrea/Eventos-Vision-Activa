@extends('publico.layout')
@section('titulo', 'Responsable')
@section('subtitulo', 'Datos de quien gestiona la inscripción')

@section('contenido')
    <form method="POST" action="{{ route('inscripcion.responsable.guardar', ['token' => $token]) }}" novalidate>
        <div class="tarjeta">
            <h2>Responsable de la inscripción</h2>
            <p class="sub">
                Es la persona de contacto ante la organización. No necesita ser participante
                ni representante legal.
            </p>

            <div class="rejilla">
                <div class="ancho">
                    <label for="kind">Tipo de inscripción</label>
                    <select id="kind" name="kind">
                        @foreach (\App\Enums\OrderKind::cases() as $tipo)
                            <option value="{{ $tipo->value }}" @selected(($valores['kind'] ?? '') === $tipo->value)>
                                {{ $tipo->label() }}
                            </option>
                        @endforeach
                    </select>
                    <div class="ayuda">
                        Institucional: una entidad paga por participantes de uno o varios establecimientos.
                        Particular: te inscribes por tu cuenta.
                    </div>
                </div>

                <div>
                    <label for="responsible_name">Nombre completo</label>
                    <input type="text" id="responsible_name" name="responsible_name" autocomplete="name"
                           value="{{ $valores['responsible_name'] ?? '' }}"
                           @if ($errores->has('responsible_name')) aria-invalid="true" @endif>
                    @if ($errores->has('responsible_name'))
                        <div class="error-campo">{{ $errores->first('responsible_name') }}</div>
                    @endif
                </div>

                @include('publico.inscripcion._cargo', ['campo' => 'responsible_position', 'opcional' => true])

                <div>
                    <label for="responsible_email">Correo electrónico</label>
                    <input type="email" id="responsible_email" value="{{ $orden->responsible_email }}" disabled>
                    <div class="ayuda">Es el correo con el que accedes a esta inscripción.</div>
                </div>

                <div>
                    <label for="responsible_phone">Teléfono <span class="opcional">(opcional)</span></label>
                    <input type="tel" id="responsible_phone" name="responsible_phone" autocomplete="tel"
                           value="{{ $valores['responsible_phone'] ?? '' }}">
                </div>

                <div class="ancho">
                    <label for="responsible_institution">Institución a la que perteneces <span class="opcional">(opcional)</span></label>
                    <input type="text" id="responsible_institution" name="responsible_institution" autocomplete="organization"
                           value="{{ $valores['responsible_institution'] ?? '' }}"
                           @if ($errores->has('responsible_institution')) aria-invalid="true" @endif>
                    @if ($errores->has('responsible_institution'))
                        <div class="error-campo">{{ $errores->first('responsible_institution') }}</div>
                    @endif
                </div>
            </div>

            <div class="acciones">
                <button type="submit" class="btn btn-primario" data-enviando-texto="Guardando…">Continuar</button>
            </div>
        </div>
    </form>
@endsection
