@php
    $accesos = $event->accessTypes->where('is_active', true);
    $yaEstaElResponsable = $orden->participants->contains(
        fn ($p) => $p->email && $orden->responsible_email && mb_strtolower($p->email) === mb_strtolower($orden->responsible_email)
    );
    // Con un solo colegio no se pregunta algo que ya se sabe.
    $variosColegios = $orden->establishments->count() > 1;
@endphp
@php
    $valorDe = fn ($p): int => (int) ($orden->esBorrador() ? ($p->accessType?->precioVigente() ?? 0) : $p->unit_price);
    $abrirAgregar = $orden->participants->isEmpty() || ($errores->isNotEmpty() && ! $errores->has('cambio'));
@endphp
<div class="tarjeta" id="participantes">
    <div class="titulo-bloque">
        <h2>Participantes{{ ($varios ?? false) && ! $orden->esBorrador() ? ' - '.$orden->establishments->first()?->name : '' }}</h2>
        @if ($orden->participants->isNotEmpty())
            <span class="chip chip-exito">{{ $orden->participants->count() }} {{ $orden->participants->count() === 1 ? 'inscrito' : 'inscritos' }}</span>
        @else
            <span class="chip chip-error">Requerido</span>
        @endif
    </div>

    @if (request('guardado') === 'participantes' && $errores->isEmpty())
        <div class="aviso aviso-exito" role="status">Cambios guardados.</div>
    @endif

    @if ($errores->has('cambio'))
        <div class="aviso aviso-error">{{ $errores->first('cambio') }}</div>
    @endif

    @foreach ($orden->participants as $participante)
        <div class="fila-persona">
            <span class="iniciales">{{ mb_strtoupper(mb_substr($participante->first_name, 0, 1).mb_substr((string) $participante->last_name, 0, 1)) }}</span>
            <div class="fila-persona-datos">
                <strong>{{ $participante->nombre_completo }}</strong>
                <span class="ayuda" style="margin:0">
                    {{ collect([$participante->rut, $participante->accessType?->name, $participante->establishment?->name])->filter()->implode(' · ') }}
                    · ${{ number_format($valorDe($participante), 0, ',', '.') }}
                </span>
                @unless ($orden->esBorrador())
                    <details class="desplegable-chico">
                        <summary>Cambiar acceso</summary>
                        <form method="POST" style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap"
                              action="{{ route('inscripcion.participantes.acceso', ['token' => $token, 'participante' => $participante->id]) }}">
                            @method('PATCH')
                            <select name="access_type_id" aria-label="Acceso de {{ $participante->nombre_completo }}" style="width:auto">
                                @foreach ($accesos as $acceso)
                                    <option value="{{ $acceso->id }}" @selected($participante->access_type_id === $acceso->id)>{{ $acceso->name }} — ${{ number_format($acceso->precioVigente(), 0, ',', '.') }}</option>
                                @endforeach
                            </select>
                            <button type="submit" class="btn btn-secundario" data-enviando-texto="Cambiando…">Cambiar</button>
                        </form>
                    </details>
                    @include('publico.portal._corregir-participante', ['participante' => $participante])
                @endunless
            </div>
            <span class="chip">{{ $orden->esBorrador() ? 'Registrado' : $participante->status->label() }}</span>
            <form method="POST"
                  action="{{ route('inscripcion.participantes.quitar', ['token' => $token, 'participante' => $participante->id]) }}"
                  data-confirmar="¿Quitar a {{ $participante->nombre_completo }} de la inscripción? Tendrás que volver a escribir sus datos si te arrepientes.">
                @method('DELETE')
                <button type="submit" class="btn btn-peligro">Quitar</button>
            </form>
        </div>
    @endforeach

    @if ($orden->participants->isNotEmpty())
        <div class="total">
            <span>{{ $orden->esBorrador() ? 'Total estimado' : 'Total' }}</span>
            <span class="monto">${{ number_format($orden->calcularTotal(), 0, ',', '.') }}</span>
        </div>
    @endif

    @if ($accesos->isEmpty())
        <div class="aviso aviso-error">
            Este evento todavía no tiene tipos de acceso disponibles. Contáctanos para poder continuar.
        </div>
    @else
        <details class="desplegable" @if ($abrirAgregar) open @endif>
            <summary class="btn btn-secundario">{{ $orden->participants->isEmpty() ? '+ Agregar participante' : '+ Agregar otro participante' }}</summary>
            <p class="etiqueta-seccion" style="margin:4px 0 12px">Nuevo participante</p>
            <form method="POST" action="{{ route('inscripcion.participantes.agregar', ['token' => $token]) }}" novalidate>
                <div class="rejilla">
                    <div>
                        <label for="first_name">Nombre</label>
                        <input type="text" id="first_name" name="first_name" value="{{ $valores['first_name'] ?? '' }}"
                               @if ($errores->has('first_name')) aria-invalid="true" @endif>
                        @if ($errores->has('first_name'))
                            <div class="error-campo">{{ $errores->first('first_name') }}</div>
                        @endif
                    </div>

                    <div>
                        <label for="last_name">Apellidos</label>
                        <input type="text" id="last_name" name="last_name" value="{{ $valores['last_name'] ?? '' }}"
                               @if ($errores->has('last_name')) aria-invalid="true" @endif>
                        @if ($errores->has('last_name'))
                            <div class="error-campo">{{ $errores->first('last_name') }}</div>
                        @endif
                    </div>

                    <div>
                        <label for="rut">RUT</label>
                        <input type="text" id="rut" name="rut" value="{{ $valores['rut'] ?? '' }}"
                               data-rut maxlength="12" autocomplete="off" placeholder="12.345.678-9"
                               @if ($errores->has('rut')) aria-invalid="true" @endif>
                        @if ($errores->has('rut'))
                            <div class="error-campo">{{ $errores->first('rut') }}</div>
                        @endif
                    </div>

                    @include('publico.inscripcion._cargo', ['campo' => 'position', 'opciones' => $event->cargosDeParticipante()])

                    {{-- Siempre a la vista, aunque haya uno solo (ya elegido): así se ve
                         a qué establecimiento queda el participante. --}}
                    @if ($orden->establishments->isNotEmpty())
                        @php $unSoloColegio = $orden->establishments->count() === 1; @endphp
                        <div>
                            <label for="establishment_id">Establecimiento</label>
                            <select id="establishment_id" name="establishment_id"
                                    @if ($errores->has('establishment_id')) aria-invalid="true" @endif>
                                @unless ($unSoloColegio)
                                    <option value="">Selecciona</option>
                                @endunless
                                @foreach ($orden->establishments as $establecimiento)
                                    <option value="{{ $establecimiento->id }}"
                                        @selected($unSoloColegio || (string) ($valores['establishment_id'] ?? '') === (string) $establecimiento->id)>
                                        {{ $establecimiento->name }}
                                    </option>
                                @endforeach
                            </select>
                            @if ($errores->has('establishment_id'))
                                <div class="error-campo">{{ $errores->first('establishment_id') }}</div>
                            @endif
                        </div>
                    @endif

                    <div>
                        <label for="email">Correo</label>
                        <input type="email" id="email" name="email" value="{{ $valores['email'] ?? '' }}"
                               @if ($errores->has('email')) aria-invalid="true" @endif>
                        @if ($errores->has('email'))
                            <div class="error-campo">{{ $errores->first('email') }}</div>
                        @endif
                    </div>

                    <div class="ancho">
                        <label for="access_type_id">Tipo de acceso</label>
                        <select id="access_type_id" name="access_type_id"
                                @if ($errores->has('access_type_id')) aria-invalid="true" @endif>
                            <option value="">Selecciona</option>
                            @foreach ($accesos as $acceso)
                                <option value="{{ $acceso->id }}"
                                    @selected((string) ($valores['access_type_id'] ?? '') === (string) $acceso->id)>
                                    {{ $acceso->name }} — ${{ number_format($acceso->precioVigente(), 0, ',', '.') }}@if ($acceso->tieneDescuentoAnticipado()) (valor anticipado hasta el {{ $acceso->early_until->format('d-m-Y') }})@endif
                                </option>
                            @endforeach
                        </select>
                        @if ($errores->has('access_type_id'))
                            <div class="error-campo">{{ $errores->first('access_type_id') }}</div>
                        @endif
                    </div>
                </div>
                <div class="acciones">
                    <button type="submit" class="btn btn-primario" data-enviando-texto="Guardando…">Guardar participante</button>
                </div>
            </form>
        </details>

    @endif
</div>
