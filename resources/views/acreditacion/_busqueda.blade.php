<div class="tarjeta">
    <h2>Buscar participante</h2>
    <p class="sub">Por nombre, RUT, correo, código de credencial o número de inscripción.</p>

    <form method="GET" action="{{ route('acreditacion.buscar') }}">
        <input type="hidden" name="event_id" value="{{ $evento?->id }}">
        <input type="search" name="q" value="{{ $consulta }}" placeholder="Ana Pérez, 12345678-9, SI-2026-0001…"
               autocomplete="off">
        <button type="submit" class="btn btn-secundario" style="margin-top:12px">Buscar</button>
    </form>
</div>

@if ($consulta !== '')
    <div class="tarjeta" style="padding:0;overflow:hidden">
        @if ($coincidencias->isEmpty())
            <div class="vacio">
                Sin resultados para “{{ $consulta }}”.<br>
                Prueba con menos palabras, o solo con el apellido.
            </div>
        @else
            @foreach ($coincidencias as $t)
                <div class="coincidencia">
                    <div class="datos">
                        <strong>{{ $t->participant->nombre_completo }}</strong>
                        <span>
                            {{ $t->participant->establishment?->name ?: 'Sin establecimiento' }}
                            · {{ $t->participant->accessType?->name }}
                            · {{ $t->order->number }}
                        </span>
                    </div>

                    @if ($t->accreditation)
                        <span class="marca-acreditado">Ya acreditado</span>
                    @else
                        <form method="POST" action="{{ route('acreditacion.resolver') }}">
                            @csrf
                            <input type="hidden" name="event_id" value="{{ $evento?->id }}">
                            <input type="hidden" name="credencial" value="{{ $t->code }}">
                            <input type="hidden" name="metodo" value="manual">
                            <button type="submit" class="btn btn-primario">Ver</button>
                        </form>
                    @endif
                </div>
            @endforeach
        @endif
    </div>
@endif
