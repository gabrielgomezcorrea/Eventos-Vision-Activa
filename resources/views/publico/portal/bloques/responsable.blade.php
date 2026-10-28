{{-- Responsable, con la forma de docs/design-references/2-inscripcion.png. --}}
<form method="POST" action="{{ route('inscripcion.responsable.guardar', ['token' => $token]) }}" novalidate>
    <div class="tarjeta" id="responsable">
        <p class="etiqueta-seccion">Datos de inscripción</p>
        <div class="titulo-bloque">
            <h2>Información del responsable</h2>
            @if (filled($orden->responsible_name) && filled($orden->responsible_lastname))
                <span class="chip chip-exito">Completado</span>
            @else
                <span class="chip chip-error">Requerido</span>
            @endif
        </div>
        @if (request('guardado') === 'responsable' && $errores->isEmpty())
            <div class="aviso aviso-exito" role="status">Cambios guardados.</div>
        @endif

        <div class="rejilla">
            <div class="ancho">
                <label for="kind">Tipo de inscripción</label>
                <select id="kind" name="kind">
                    @foreach (\App\Enums\OrderKind::elegiblesPorElCliente() as $tipo)
                        <option value="{{ $tipo->value }}" @selected(($valores['kind'] ?? '') === $tipo->value)>{{ $tipo->label() }}</option>
                    @endforeach
                </select>
            </div>

            @include('publico.inscripcion._campo', ['campo' => 'responsible_name', 'etiqueta' => 'Nombre', 'autocomplete' => 'given-name'])
            @include('publico.inscripcion._campo', ['campo' => 'responsible_lastname', 'etiqueta' => 'Apellidos', 'autocomplete' => 'family-name'])

            <div>
                <label for="responsible_email">Correo electrónico</label>
                <input type="email" id="responsible_email" name="responsible_email" value="{{ $valores['responsible_email'] ?? $orden->responsible_email }}"
                       autocomplete="email" inputmode="email" @if ($errores->has('responsible_email')) aria-invalid="true" @endif>
                @if ($errores->has('responsible_email'))
                    <div class="error-campo">{{ $errores->first('responsible_email') }}</div>
                @endif
            </div>
            @include('publico.inscripcion._campo', ['campo' => 'responsible_phone', 'etiqueta' => 'Teléfono', 'tipoCampo' => 'tel', 'placeholder' => '56912345678', 'autocomplete' => 'tel'])

            @include('publico.inscripcion._campo', ['campo' => 'responsible_institution', 'etiqueta' => 'Establecimiento', 'autocomplete' => 'organization', 'opcional' => ($valores['kind'] ?? '') === \App\Enums\OrderKind::Particular->value])
            @include('publico.inscripcion._cargo', ['campo' => 'responsible_position'])
        </div>

        <div class="acciones" style="align-items:center">
            <button type="submit" class="btn btn-primario" data-enviando-texto="Guardando…">Guardar cambios</button>
        </div>
    </div>
</form>
