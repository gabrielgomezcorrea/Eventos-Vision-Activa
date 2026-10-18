{{--
    Varios establecimientos en una misma inscripción (decisión del 17/09/2026,
    confirmada por Gabriel el 15/10/2026): cada participante elige el suyo y,
    al confirmar, cada colegio queda como su propia inscripción con su pago y
    su factura (App\Actions\ConfirmarConjunto).
--}}
@if ($orden->parent_order_id !== null)
    {{-- Inscripción adicional: es el mismo colegio de la original. --}}
    <div class="tarjeta" id="establecimientos">
        <div class="titulo-bloque">
            <h2>Establecimientos</h2>
            <span class="chip chip-exito">Completado</span>
        </div>
        @foreach ($orden->establishments as $establecimiento)
            <div class="fila-persona">
                <div class="fila-persona-datos">
                    <strong>{{ $establecimiento->name }}</strong>
                    <span class="ayuda" style="margin:0">{{ collect([$establecimiento->rbd ? 'RBD '.$establecimiento->rbd : null, $establecimiento->address, $establecimiento->commune])->filter()->implode(' · ') }}</span>
                </div>
            </div>
        @endforeach
    </div>
@else
@php $establecimiento = $orden->establishments->first(); @endphp
<div class="tarjeta" id="establecimientos">
    <div class="titulo-bloque">
        <h2>Establecimientos</h2>
        @if ($establecimiento)
            <span class="chip chip-exito">Completado</span>
        @else
            <span class="chip chip-error">Requerido</span>
        @endif
    </div>
    <p class="sub">Inscribe un establecimiento. Recuerda: si quieres inscribir más, lo puedes hacer después de completar tu inscripción.</p>
    @if (request('guardado') === 'establecimientos' && $errores->isEmpty())
        <div class="aviso aviso-exito" role="status">Cambios guardados.</div>
    @endif

    @if ($establecimiento)
        @foreach ($orden->establishments as $establecimiento)
            <div class="fila-persona">
                <div class="fila-persona-datos">
                    <strong>{{ $establecimiento->name }}</strong>
                    <span class="ayuda" style="margin:0">{{ collect([$establecimiento->rbd ? 'RBD '.$establecimiento->rbd : null, $establecimiento->address, $establecimiento->commune])->filter()->implode(' · ') }}</span>
                </div>
                <form method="POST"
                      action="{{ route('inscripcion.establecimientos.quitar', ['token' => $token, 'establishment' => $establecimiento]) }}"
                      data-confirmar="¿Quitar «{{ $establecimiento->name }}» de la inscripción?{{ ($n = $orden->participants->where('establishment_id', $establecimiento->id)->count()) > 0 ? ' También se quitarán sus '.$n.' participante(s).' : '' }}">
                    @method('DELETE')
                    <button type="submit" class="btn btn-peligro">Quitar</button>
                </form>
            </div>
        @endforeach
    @endif

    @php
        // El primero viene con el nombre que ya se escribió arriba en el responsable.
        if (! $establecimiento) {
            $valores = $valores + ['name' => $orden->responsible_institution];
        }
    @endphp
    <details class="desplegable" @if (! $establecimiento || $errores->isNotEmpty()) open @endif>
        <summary class="btn btn-secundario">{{ $establecimiento ? '+ Agregar otro establecimiento' : '+ Agregar establecimiento' }}</summary>
        <p class="etiqueta-seccion" style="margin:4px 0 12px">Nuevo establecimiento</p>
        <form method="POST" action="{{ route('inscripcion.establecimientos.agregar', ['token' => $token]) }}" novalidate>
            <div class="rejilla">
                @include('publico.inscripcion._campo', ['prefijo' => 'est_', 'campo' => 'name', 'etiqueta' => 'Nombre del establecimiento'])
                @include('publico.inscripcion._campo', ['prefijo' => 'est_', 'campo' => 'rbd', 'etiqueta' => 'RBD', 'opcional' => true, 'placeholder' => '12345-6', 'autocomplete' => 'off'])
                @include('publico.inscripcion._campo', ['prefijo' => 'est_', 'campo' => 'address', 'etiqueta' => 'Dirección', 'autocomplete' => 'street-address'])
                @include('publico.inscripcion._campo', ['prefijo' => 'est_', 'campo' => 'commune', 'etiqueta' => 'Comuna', 'autocomplete' => 'off', 'atributos' => 'list="comunas"'])
            </div>
            <div class="acciones" style="align-items:center">
                <button type="submit" class="btn btn-primario" data-enviando-texto="Guardando…">Guardar establecimiento</button>
            </div>
        </form>
    </details>
</div>
@endif
