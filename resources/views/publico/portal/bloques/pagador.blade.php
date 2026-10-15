    <form method="POST" action="{{ route('inscripcion.pagador.guardar', ['token' => $token]) }}" novalidate>
        <div class="tarjeta" id="pagador">

            <div class="titulo-bloque">
            <h2>Entidad pagadora</h2>
            @if ($orden->payer_entity_id !== null)<span class="chip chip-exito">Completado</span>@else<span class="chip chip-error">Requerido</span>@endif
        </div>
    @if (request('guardado') === 'pagador' && $errores->isEmpty())
        <div class="aviso aviso-exito" role="status">Cambios guardados.</div>
    @endif

            <div class="rejilla">
                @include('publico.inscripcion._campo', ['prefijo' => 'pagador_', 'campo' => 'name', 'etiqueta' => 'Nombre o razón social', 'ancho' => true])
                @include('publico.inscripcion._campo', ['prefijo' => 'pagador_', 'campo' => 'rut', 'etiqueta' => 'RUT', 'placeholder' => '76.086.428-5', 'autocomplete' => 'off', 'atributos' => 'data-rut maxlength="12"'])
                @include('publico.inscripcion._campo', ['prefijo' => 'pagador_', 'campo' => 'address', 'etiqueta' => 'Dirección', 'autocomplete' => 'street-address'])
                @include('publico.inscripcion._campo', ['prefijo' => 'pagador_', 'campo' => 'commune', 'etiqueta' => 'Comuna', 'autocomplete' => 'off', 'atributos' => 'list="comunas"'])
                @include('publico.inscripcion._campo', ['prefijo' => 'pagador_', 'campo' => 'billing_email', 'etiqueta' => 'Correo de facturación', 'tipoCampo' => 'email', 'autocomplete' => 'email'])
                @include('publico.inscripcion._campo', ['prefijo' => 'pagador_', 'campo' => 'phone', 'etiqueta' => 'Teléfono', 'tipoCampo' => 'tel', 'placeholder' => '56912345678', 'autocomplete' => 'tel'])
            </div>
            
            <div class="acciones" style="align-items:center">
                <button type="submit" class="btn btn-primario" data-enviando-texto="Guardando…">Guardar cambios</button>
            </div>
        </div>
    </form>
