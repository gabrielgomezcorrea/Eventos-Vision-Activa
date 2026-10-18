{{--
    "¿Necesitas inscribir a más personas?": crea una inscripción nueva del mismo
    colegio con su propio pago, o una para otro establecimiento, sin tocar la ya
    confirmada. Solo en una
    inscripción confirmada o pagada de un evento que sigue abierto; un invitado
    no inscribe a más gente.
--}}
@if (! $orden->esBorrador()
    && in_array($orden->status, [\App\Enums\OrderStatus::Reservada, \App\Enums\OrderStatus::Finalizada], true)
    && $orden->kind !== \App\Enums\OrderKind::Invitado
    && $event->admiteInscripciones())
    @php
        $parametros = ['token' => $token] + ($varios ? ['inscripcion' => $orden->public_id] : []);
    @endphp
    <div class="rejilla" style="margin-bottom:18px">
        <div class="tarjeta" style="margin:0;border-top:4px solid var(--primario);display:flex;flex-direction:column">
            <span class="chip" style="align-self:flex-start;margin-bottom:10px">Mismo colegio</span>
            <h3 style="margin:0 0 4px">¿Quieres inscribir más participantes?</h3>
            <p class="sub" style="flex:1">Sumas personas a {{ $orden->nombreDelColegio() ?? 'tu establecimiento' }}, con su propio pago.</p>
            <form method="POST" action="{{ route('inscripcion.adicional', $parametros) }}">
                <button type="submit" class="btn btn-primario" style="width:100%" data-enviando-texto="Creando…">Inscribir más participantes</button>
            </form>
        </div>
        <div class="tarjeta" style="margin:0;border-top:4px solid var(--acento);display:flex;flex-direction:column">
            <span class="chip chip-aviso" style="align-self:flex-start;margin-bottom:10px">Otro colegio</span>
            <h3 style="margin:0 0 4px">¿Quieres agregar otro establecimiento?</h3>
            <p class="sub" style="flex:1">Inscribes a otro colegio, con sus participantes y su propio pago.</p>
            <form method="POST" action="{{ route('inscripcion.otro-establecimiento', $parametros) }}">
                <button type="submit" class="btn btn-secundario" style="width:100%" data-enviando-texto="Creando…">Agregar otro establecimiento</button>
            </form>
        </div>
    </div>
@endif
