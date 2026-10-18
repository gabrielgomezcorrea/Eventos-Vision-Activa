{{--
    Código de descuento en la columna izquierda, a la vista desde el inicio.
    Solo mientras la inscripción se está completando: el precio se congela al
    confirmar y, con todo completo, esta caja se oculta.
--}}
<div class="lado-caja" id="codigo">
    <p class="lado-titulo">Código de descuento</p>
    @if ($orden->discountCode)
        @php $alcance = $orden->discountCode->etiqueta(); @endphp
        <p style="margin:0 0 8px;font-size:.9rem;color:var(--exito)">
            <strong>Descuento aplicado ({{ $alcance === 'Descuento completo' ? 'completo' : $alcance }})</strong>
        </p>
        <form method="POST" action="{{ route('inscripcion.codigo.quitar', ['token' => $token]) }}" style="margin:0">
            <button type="submit" class="btn btn-secundario">Quitar código</button>
        </form>
    @else
        <form method="POST" action="{{ route('inscripcion.codigo.aplicar', ['token' => $token]) }}" style="margin:0">
            <label for="codigo-descuento" style="font-size:.85rem">¿Tienes un código?</label>
            <input type="text" id="codigo-descuento" name="codigo" maxlength="24"
                   value="{{ $codigoEscrito ?? '' }}" autocomplete="off" autocapitalize="characters"
                   style="width:100%;text-transform:uppercase;margin-bottom:8px">
            <button type="submit" class="btn btn-secundario" data-enviando-texto="Aplicando…">Aplicar</button>
        </form>
        @if ($errorDeCodigo ?? null)
            <div class="aviso aviso-error" style="margin:10px 0 0">{{ $errorDeCodigo }}</div>
        @endif
    @endif
</div>
