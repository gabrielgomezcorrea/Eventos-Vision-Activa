{{--
    Corregir los datos de contacto de un participante (nombre, correo,
    teléfono y cargo). El RUT, el acceso y el establecimiento no se tocan.
    Variables: $participante, $orden, $token, $event.
--}}
@php
    $otroCargo = \App\Support\Forms\ProgramFormField::CARGO_OTRO;
    $opcionesDeCargo = $event->cargosDeParticipante();
    $cargoGuardado = $participante->position;
    $esOtro = $cargoGuardado !== null && ! in_array($cargoGuardado, $opcionesDeCargo, true);
@endphp
<details style="margin-top:6px">
    <summary style="cursor:pointer;color:var(--primario);font-size:.85rem">Corregir datos</summary>
    <form method="POST" action="{{ route('inscripcion.participantes.corregir', ['token' => $token, 'participante' => $participante->id]) }}" novalidate style="margin-top:10px">
        @method('PATCH')
        <div class="rejilla">
            <div>
                <label for="c-nombre-{{ $participante->id }}">Nombre</label>
                <input type="text" id="c-nombre-{{ $participante->id }}" name="first_name" value="{{ $participante->first_name }}">
            </div>
            <div>
                <label for="c-apellidos-{{ $participante->id }}">Apellidos</label>
                <input type="text" id="c-apellidos-{{ $participante->id }}" name="last_name" value="{{ $participante->last_name }}">
            </div>
            <div>
                <label for="c-correo-{{ $participante->id }}">Correo</label>
                <input type="email" id="c-correo-{{ $participante->id }}" name="email" value="{{ $participante->email }}">
            </div>
            <div>
                <label for="c-telefono-{{ $participante->id }}">Teléfono <span class="opcional">(opcional)</span></label>
                <input type="tel" id="c-telefono-{{ $participante->id }}" name="phone" value="{{ $participante->phone }}" placeholder="56912345678">
            </div>
            <div>
                <label for="c-cargo-{{ $participante->id }}">Cargo</label>
                <select id="c-cargo-{{ $participante->id }}" name="position">
                    @foreach ($opcionesDeCargo as $opcion)
                        <option value="{{ $opcion }}" @selected($esOtro ? $opcion === $otroCargo : $cargoGuardado === $opcion)>{{ $opcion === $otroCargo ? 'Otro (especificar)' : $opcion }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="c-otro-{{ $participante->id }}">Otro cargo <span class="opcional">(solo si elegiste Otro)</span></label>
                <input type="text" id="c-otro-{{ $participante->id }}" name="position_otro" value="{{ $esOtro ? $cargoGuardado : '' }}" maxlength="120">
            </div>
        </div>
        <div class="acciones">
            <button type="submit" class="btn btn-secundario" data-enviando-texto="Guardando…">Guardar cambios</button>
        </div>
    </form>
</details>
