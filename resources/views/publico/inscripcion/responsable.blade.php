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
                        @foreach (\App\Enums\OrderKind::elegiblesPorElCliente() as $tipo)
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

                @include('publico.inscripcion._campo', ['campo' => 'responsible_name', 'etiqueta' => 'Nombre', 'autocomplete' => 'given-name'])
                @include('publico.inscripcion._campo', ['campo' => 'responsible_lastname', 'etiqueta' => 'Apellidos', 'autocomplete' => 'family-name'])

                @include('publico.inscripcion._cargo', ['campo' => 'responsible_position'])

                <div>
                    <label for="responsible_email">Correo del responsable de compra</label>
                    <input type="email" id="responsible_email" value="{{ $orden->responsible_email }}" disabled>
                    <div class="ayuda">Es el correo con el que accedes a esta inscripción.</div>
                </div>

                @include('publico.inscripcion._campo', ['campo' => 'responsible_phone', 'etiqueta' => 'Teléfono', 'tipoCampo' => 'tel', 'placeholder' => '56912345678', 'autocomplete' => 'tel'])
                @include('publico.inscripcion._campo', ['campo' => 'responsible_institution', 'etiqueta' => 'Institución a la que perteneces', 'autocomplete' => 'organization', 'ancho' => true, 'opcional' => ($valores['kind'] ?? '') === \App\Enums\OrderKind::Particular->value])
            </div>

            <div class="acciones">
                <button type="submit" class="btn btn-primario" data-enviando-texto="Guardando…">Continuar</button>
            </div>
        </div>
    </form>
@endsection
