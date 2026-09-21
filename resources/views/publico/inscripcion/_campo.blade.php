{{--
    Text input with its label and error, the same in every step.
    Variables: $campo, $etiqueta, $valores, $errores; optional $tipoCampo, $opcional,
    $placeholder, $autocomplete, $ayuda, $ancho, $atributos (extra raw attributes).
--}}
<div @if ($ancho ?? false) class="ancho" @endif>
    <label for="{{ $campo }}">{{ $etiqueta }} @if ($opcional ?? false)<span class="opcional">(opcional)</span>@endif</label>
    <input type="{{ $tipoCampo ?? 'text' }}" id="{{ $campo }}" name="{{ $campo }}"
           value="{{ $valores[$campo] ?? '' }}"
           @isset($placeholder) placeholder="{{ $placeholder }}" @endisset
           @isset($autocomplete) autocomplete="{{ $autocomplete }}" @endisset
           {!! $atributos ?? '' !!}
           @if ($errores->has($campo)) aria-invalid="true" @endif>
    @if ($errores->has($campo))
        <div class="error-campo">{{ $errores->first($campo) }}</div>
    @elseif (isset($ayuda))
        <div class="ayuda">{{ $ayuda }}</div>
    @endif
</div>
