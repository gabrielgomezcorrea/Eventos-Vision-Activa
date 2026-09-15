@extends('publico.layout')
@section('titulo', 'Participantes')
@section('subtitulo', 'Personas que asistirán al evento')

@php
    $accesos = $event->accessTypes->where('is_active', true);
    $requiereEstablecimiento = $orden->kind->requiereEstablecimiento() && $orden->establishments->isNotEmpty();
    $yaEstaElResponsable = $orden->participants->contains(
        fn ($p) => $p->email && $orden->responsible_email && mb_strtolower($p->email) === mb_strtolower($orden->responsible_email)
    );
@endphp

@section('contenido')
    <div class="tarjeta">
        <h2>Participantes registrados</h2>
        <p class="sub">{{ $orden->participants->count() }} participante(s) en esta inscripción.</p>

        @if ($orden->participants->isEmpty())
            <div class="vacio">Todavía no has agregado participantes.</div>
        @else
            <table>
                <thead>
                    <tr>
                        <th>Participante</th><th>RUT</th><th>Establecimiento</th>
                        <th>Acceso</th><th style="text-align:right">Valor</th><th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($orden->participants as $participante)
                        <tr>
                            <td>
                                <strong>{{ $participante->nombre_completo }}</strong>
                                @if ($participante->position)
                                    <div class="ayuda">{{ $participante->position }}</div>
                                @endif
                            </td>
                            <td>{{ $participante->rut ?: '—' }}</td>
                            <td>{{ $participante->establishment?->name ?: '—' }}</td>
                            <td><span class="chip">{{ $participante->accessType?->name }}</span></td>
                            <td style="text-align:right">${{ number_format($participante->accessType?->precioVigente() ?? 0, 0, ',', '.') }}</td>
                            <td style="text-align:right">
                                <form method="POST"
                                      action="{{ route('inscripcion.participantes.quitar', ['token' => $token, 'participante' => $participante->id]) }}"
                                      data-confirmar="¿Quitar a {{ $participante->nombre_completo }} de la inscripción? Tendrás que volver a escribir sus datos si te arrepientes.">
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-peligro">Quitar</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

@if ($orden->calcularDescuento() > 0)
    <table>
        <tbody>
            <tr>
                <td style="color:var(--texto-suave)">Subtotal</td>
                <td style="text-align:right">${{ number_format($orden->calcularSubtotal(), 0, ',', '.') }}</td>
            </tr>
            <tr>
                <td style="color:var(--exito)">{{ $orden->tramoDeDescuento()?->etiqueta() }}</td>
                <td style="text-align:right;color:var(--exito)">
                    -${{ number_format($orden->calcularDescuento(), 0, ',', '.') }}
                </td>
            </tr>
        </tbody>
    </table>
@endif

            <div class="total">
                <span>Total estimado</span>
                <span class="monto">${{ number_format($orden->calcularTotal(), 0, ',', '.') }}</span>
            </div>
        @endif
    </div>

    @if ($accesos->isEmpty())
        <div class="aviso aviso-error">
            Este evento todavía no tiene tipos de acceso disponibles. Contáctanos para poder continuar.
        </div>
    @else
        <form method="POST" action="{{ route('inscripcion.participantes.agregar', ['token' => $token]) }}" novalidate>
            <div class="tarjeta">
                <h2>Agregar participante</h2>
                <p class="sub">Estos datos se usarán para la acreditación y la certificación.</p>

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
                        <label for="rut">RUT <span class="opcional">(opcional)</span></label>
                        <input type="text" id="rut" name="rut" value="{{ $valores['rut'] ?? '' }}"
                               data-rut maxlength="12" autocomplete="off" placeholder="12.345.678-9"
                               @if ($errores->has('rut')) aria-invalid="true" @endif>
                        @if ($errores->has('rut'))
                            <div class="error-campo">{{ $errores->first('rut') }}</div>
                        @endif
                    </div>

                    @include('publico.inscripcion._cargo', ['campo' => 'position'])

                    <div>
                        <label for="email">Correo</label>
                        <input type="email" id="email" name="email" value="{{ $valores['email'] ?? '' }}"
                               @if ($errores->has('email')) aria-invalid="true" @endif>
                        @if ($errores->has('email'))
                            <div class="error-campo">{{ $errores->first('email') }}</div>
                        @else
                            <div class="ayuda">Le enviaremos su credencial a este correo.</div>
                        @endif
                    </div>

                    @if ($orden->establishments->isNotEmpty())
                        <div>
                            <label for="establishment_id">
                                Establecimiento
                                @unless ($requiereEstablecimiento)<span class="opcional">(opcional)</span>@endunless
                            </label>
                            <select id="establishment_id" name="establishment_id"
                                    @if ($errores->has('establishment_id')) aria-invalid="true" @endif>
                                <option value="">Selecciona</option>
                                @foreach ($orden->establishments as $establecimiento)
                                    <option value="{{ $establecimiento->id }}"
                                        @selected((string) ($valores['establishment_id'] ?? '') === (string) $establecimiento->id)>
                                        {{ $establecimiento->name }}
                                    </option>
                                @endforeach
                            </select>
                            @if ($errores->has('establishment_id'))
                                <div class="error-campo">{{ $errores->first('establishment_id') }}</div>
                            @endif
                        </div>
                    @endif

                    <div class="{{ $orden->establishments->isEmpty() ? '' : 'ancho' }}">
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
                    <button type="submit" class="btn btn-secundario" data-enviando-texto="Agregando…">Agregar participante</button>
                </div>
            </div>
        </form>

        @if ($orden->responsible_name && ! $yaEstaElResponsable)
            <form method="POST" action="{{ route('inscripcion.participantes.responsable', ['token' => $token]) }}">
                <div class="tarjeta">
                    <h2>¿El responsable también participará?</h2>
                    <p class="sub">
                        Agregamos a {{ $orden->responsible_name }} como participante reutilizando sus datos.
                        Solo debes elegir su acceso.
                    </p>
                    <div class="rejilla">
                        @if ($orden->establishments->isNotEmpty())
                            <div>
                                <label for="r_establishment_id">Establecimiento</label>
                                <select id="r_establishment_id" name="establishment_id">
                                    <option value="">Selecciona</option>
                                    @foreach ($orden->establishments as $establecimiento)
                                        <option value="{{ $establecimiento->id }}">{{ $establecimiento->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif
                        <div>
                            <label for="r_access_type_id">Tipo de acceso</label>
                            <select id="r_access_type_id" name="access_type_id">
                                <option value="">Selecciona</option>
                                @foreach ($accesos as $acceso)
                                    <option value="{{ $acceso->id }}">{{ $acceso->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="acciones">
                        <button type="submit" class="btn btn-secundario" data-enviando-texto="Agregando…">Agregarme como participante</button>
                    </div>
                </div>
            </form>
        @endif
    @endif

    <div class="acciones">
        <a href="{{ route('inscripcion.establecimientos', ['token' => $token]) }}" class="btn btn-secundario">Volver</a>
        @if ($orden->participants->isNotEmpty())
            <a href="{{ route('inscripcion.resumen', ['token' => $token]) }}" class="btn btn-primario">Revisar inscripción</a>
        @endif
    </div>
@endsection
