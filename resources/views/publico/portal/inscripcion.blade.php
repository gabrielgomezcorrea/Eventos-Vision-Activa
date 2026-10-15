@extends('publico.portal.base')

@section('pestana')
@if ($orden->esBorrador())
    {{-- Cuatro bloques libres: se completan en cualquier orden y cada uno guarda
         por su cuenta. Un error vuelve en su bloque sin borrar los otros. --}}
    @include('publico.inscripcion._comunas')

    @include('publico.portal.bloques.responsable', ['valores' => $valoresPorBloque['responsable'], 'errores' => $erroresPorBloque['responsable']])
    @include('publico.portal.bloques.establecimientos', ['valores' => $valoresPorBloque['establecimientos'], 'errores' => $erroresPorBloque['establecimientos']])
    @include('publico.portal.bloques.participantes', ['valores' => $valoresPorBloque['participantes'], 'errores' => $erroresPorBloque['participantes']])
    @include('publico.portal.bloques.pagador', ['valores' => $valoresPorBloque['pagador'], 'errores' => $erroresPorBloque['pagador']])

@else
    @php $cuerpoEditable = $nivel->permiteCambiarLaInscripcion(); @endphp

    @if (! $cuerpoEditable)
        <div class="aviso aviso-info">
            @if ($nivel->permiteCorregirDatos())
                Ya enviaste un pago: para cambiar participantes escríbenos. Los datos de contacto sí los puedes corregir aquí.
            @else
                Esta inscripción ya no se puede cambiar desde aquí. Si necesitas algo, escríbenos.
            @endif
            @include('publico._contacto', ['evento' => $event])
        </div>
    @endif

    <div class="tarjeta" id="responsable">
        <p class="etiqueta-seccion">Datos de inscripción</p>
        <h2 style="margin-bottom:16px">Información del responsable</h2>
        @if (request('guardado') === 'responsable' && $erroresPorBloque['responsable']->isEmpty())
            <div class="aviso aviso-exito" role="status">Cambios guardados.</div>
        @endif
        @if ($erroresPorBloque['responsable']->has('cambio'))
            <div class="aviso aviso-error">{{ $erroresPorBloque['responsable']->first('cambio') }}</div>
        @endif

        @if ($nivel->permiteCorregirDatos())
            @php
                $valoresResp = $valoresPorBloque['responsable'];
                $erroresResp = $erroresPorBloque['responsable'];
            @endphp
            <form method="POST" action="{{ route('inscripcion.responsable.corregir', ['token' => $token]) }}" novalidate>
                @method('PATCH')
                <div class="rejilla">
                    @include('publico.inscripcion._campo', ['valores' => $valoresResp, 'errores' => $erroresResp, 'prefijo' => 'corregir_', 'campo' => 'responsible_name', 'etiqueta' => 'Nombre', 'autocomplete' => 'given-name'])
                    @include('publico.inscripcion._campo', ['valores' => $valoresResp, 'errores' => $erroresResp, 'prefijo' => 'corregir_', 'campo' => 'responsible_lastname', 'etiqueta' => 'Apellidos', 'autocomplete' => 'family-name'])
                    <div>
                        <label for="corregir_correo">Correo electrónico</label>
                        <input type="email" id="corregir_correo" value="{{ $orden->responsible_email }}" disabled>
                    </div>
                    @include('publico.inscripcion._campo', ['valores' => $valoresResp, 'errores' => $erroresResp, 'prefijo' => 'corregir_', 'campo' => 'responsible_phone', 'etiqueta' => 'Teléfono', 'tipoCampo' => 'tel', 'placeholder' => '56912345678', 'autocomplete' => 'tel'])
                    <div>
                        <label for="corregir_establecimiento">Establecimiento</label>
                        <input type="text" id="corregir_establecimiento" value="{{ $orden->establishments->pluck('name')->implode(', ') ?: $orden->responsible_institution }}" disabled>
                    </div>
                    @include('publico.inscripcion._cargo', ['valores' => $valoresResp, 'errores' => $erroresResp, 'campo' => 'responsible_position'])
                </div>
                <div class="acciones">
                    <button type="submit" class="btn btn-primario" data-enviando-texto="Guardando…">Guardar cambios</button>
                </div>
            </form>
        @else
            <table>
                <tbody>
                    <tr><td style="width:38%;color:var(--texto-suave)">Nombre</td><td>{{ $orden->responsableNombreCompleto() }}</td></tr>
                    <tr><td style="color:var(--texto-suave)">Correo</td><td>{{ $orden->responsible_email }}</td></tr>
                    <tr><td style="color:var(--texto-suave)">Teléfono</td><td>{{ $orden->responsible_phone ?: '—' }}</td></tr>
                    <tr><td style="color:var(--texto-suave)">Establecimiento</td><td>{{ $orden->establishments->pluck('name')->implode(', ') ?: '—' }}</td></tr>
                    <tr><td style="color:var(--texto-suave)">Cargo</td><td>{{ $orden->responsible_position ?: '—' }}</td></tr>
                </tbody>
            </table>
        @endif
    </div>

    @if ($cuerpoEditable)
        {{-- Sin abonos: se agregan y quitan personas, se cambia el acceso y se
             edita la entidad pagadora. El monto y los cupos se ajustan solos. --}}
        @include('publico.inscripcion._comunas')
        @php $accesos = $event->accessTypes->where('is_active', true); @endphp
        @include('publico.portal.bloques.participantes', ['valores' => $valoresPorBloque['participantes'], 'errores' => $erroresPorBloque['participantes']])
        @include('publico.portal.bloques.pagador', ['valores' => $valoresPorBloque['pagador'], 'errores' => $erroresPorBloque['pagador']])
    @else
        <div class="tarjeta" id="participantes">
            <h2 style="margin-bottom:8px">Participantes{{ $varios ? ' - '.$orden->establishments->first()?->name : '' }}</h2>
            @if ($erroresPorBloque['participantes']->isNotEmpty())
                <div class="aviso aviso-error">{{ $erroresPorBloque['participantes']->first() }}</div>
            @endif
            @foreach ($participantes as $participante)
                <div class="fila-persona">
                    <span class="iniciales">{{ mb_strtoupper(mb_substr($participante->first_name, 0, 1).mb_substr((string) $participante->last_name, 0, 1)) }}</span>
                    <div class="fila-persona-datos">
                        <strong>{{ $participante->nombre_completo }}</strong>
                        <span class="ayuda" style="margin:0">{{ collect([$participante->rut, $participante->accessType?->name])->filter()->implode(' · ') }}</span>
                        @if ($nivel->permiteCorregirDatos())
                            @include('publico.portal._corregir-participante', ['participante' => $participante])
                        @endif
                    </div>
                    <span class="chip">{{ $participante->status->label() }}</span>
                </div>
            @endforeach
        </div>
    @endif
@endif
@endsection
