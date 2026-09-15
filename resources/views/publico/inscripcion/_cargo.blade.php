{{--
    Cargo como lista cerrada, igual que en el formulario público: es el filtro
    principal de la exportación. "Otro" abre un campo para escribirlo.
    Variables: $campo (nombre del input), $valores, $errores, $opcional.
--}}
@php
    $otro = \App\Support\Forms\ProgramFormField::CARGO_OTRO;
    $valorCargo = $valores[$campo] ?? '';
    $claveOtro = $campo.'_otro';
@endphp
<div>
    <label for="{{ $campo }}">Cargo @if ($opcional ?? false)<span class="opcional">(opcional)</span>@endif</label>
    <select id="{{ $campo }}" name="{{ $campo }}"
            @if ($errores->has($campo)) aria-invalid="true" @endif
            onchange="document.getElementById('{{ $claveOtro }}_envoltura').hidden = this.value !== '{{ $otro }}'">
        <option value="">Selecciona una opción</option>
        @foreach (\App\Support\Forms\ProgramFormField::CARGOS as $opcion)
            <option value="{{ $opcion }}" @selected($valorCargo === $opcion)>{{ $opcion === $otro ? 'Otro (especificar)' : $opcion }}</option>
        @endforeach
    </select>
    @if ($errores->has($campo))
        <div class="error-campo">{{ $errores->first($campo) }}</div>
    @endif
    <div id="{{ $claveOtro }}_envoltura" style="margin-top:10px" @if ($valorCargo !== $otro) hidden @endif>
        <input type="text" id="{{ $claveOtro }}" name="{{ $claveOtro }}"
               value="{{ $valores[$claveOtro] ?? '' }}" placeholder="Escribe el cargo" maxlength="120"
               @if ($errores->has($claveOtro)) aria-invalid="true" @endif>
        @if ($errores->has($claveOtro))
            <div class="error-campo">{{ $errores->first($claveOtro) }}</div>
        @endif
    </div>
</div>
