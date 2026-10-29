@if ($orden->parent_order_id !== null)
    {{-- Inscripción adicional: es la misma entidad pagadora de la original, y
         editarla cambiaría su factura. --}}
    @php $entidad = $orden->payerEntity; @endphp
    <div class="tarjeta" id="pagador">
        <div class="titulo-bloque">
            <h2>Datos de facturación</h2>
            <span class="chip chip-exito">Completado</span>
        </div>
        <table>
            <tbody>
                <tr><td style="width:38%;color:var(--texto-suave)">Razón social o nombre completo</td><td>{{ $entidad?->name ?? '-' }}</td></tr>
                <tr><td style="color:var(--texto-suave)">RUT</td><td>{{ $entidad?->rut ?? '-' }}</td></tr>
                @if ($entidad?->business_activity)
                    <tr><td style="color:var(--texto-suave)">Giro</td><td>{{ $entidad->business_activity }}</td></tr>
                @endif
                <tr><td style="color:var(--texto-suave)">Dirección</td><td>{{ collect([$entidad?->address, $entidad?->commune])->filter()->implode(', ') ?: '-' }}</td></tr>
                <tr><td style="color:var(--texto-suave)">Correo receptor de la factura</td><td>{{ $entidad?->billing_email ?? '-' }}</td></tr>
                @if ($entidad?->billing_reference)
                    <tr><td style="color:var(--texto-suave)">Referencia administrativa</td><td>{{ $entidad->billing_reference }}</td></tr>
                @endif
            </tbody>
        </table>
    </div>
@else
    <form method="POST" action="{{ route('inscripcion.pagador.guardar', ['token' => $token]) }}" novalidate>
        <div class="tarjeta" id="pagador">

            <div class="titulo-bloque">
            <h2>Datos de facturación</h2>
            @if ($orden->payer_entity_id !== null)<span class="chip chip-exito">Completado</span>@else<span class="chip chip-error">Requerido</span>@endif
        </div>
    @if (request('guardado') === 'pagador' && $errores->isEmpty())
        <div class="aviso aviso-exito" role="status">Cambios guardados.</div>
    @endif

            <p class="sub">Ingrese los datos de la persona o institución a cuyo nombre debe emitirse la factura.</p>

            <div class="rejilla">
                @include('publico.inscripcion._campo', ['prefijo' => 'pagador_', 'campo' => 'rut', 'etiqueta' => 'RUT', 'placeholder' => '76.086.428-5', 'autocomplete' => 'off', 'atributos' => 'data-rut maxlength="12"'])
                @include('publico.inscripcion._campo', ['prefijo' => 'pagador_', 'campo' => 'name', 'etiqueta' => 'Razón social o nombre completo', 'ancho' => true])
                @include('publico.inscripcion._campo', ['prefijo' => 'pagador_', 'campo' => 'business_activity', 'etiqueta' => 'Giro', 'opcional' => true, 'placeholder' => 'Educación', 'autocomplete' => 'off'])
                @include('publico.inscripcion._campo', ['prefijo' => 'pagador_', 'campo' => 'address', 'etiqueta' => 'Dirección', 'autocomplete' => 'street-address'])
                @include('publico.inscripcion._campo', ['prefijo' => 'pagador_', 'campo' => 'commune', 'etiqueta' => 'Comuna', 'autocomplete' => 'off', 'atributos' => 'list="comunas"'])
                @include('publico.inscripcion._campo', ['prefijo' => 'pagador_', 'campo' => 'billing_email', 'etiqueta' => 'Correo receptor de la factura', 'tipoCampo' => 'email', 'autocomplete' => 'email'])
                @include('publico.inscripcion._campo', ['prefijo' => 'pagador_', 'campo' => 'billing_reference', 'etiqueta' => 'Referencia administrativa', 'opcional' => true, 'ancho' => true, 'placeholder' => 'N° de orden de compra o referencia interna', 'autocomplete' => 'off'])
            </div>
            
            <div class="acciones" style="align-items:center">
                <button type="submit" class="btn btn-primario" data-enviando-texto="Guardando…">Guardar cambios</button>
            </div>
        </div>
    </form>
@endif
