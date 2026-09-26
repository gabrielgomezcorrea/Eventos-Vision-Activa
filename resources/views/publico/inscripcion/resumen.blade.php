@extends('publico.layout')
@section('titulo', 'Resumen')
@section('subtitulo', 'Revisa antes de confirmar')

@section('contenido')
    <div class="aviso aviso-info">
        Revisa que todo esté correcto. Puedes volver a cualquier paso para corregir.
    </div>

    <div class="tarjeta">
        <h2>Responsable</h2>
        <p class="sub">Contacto operativo de la inscripción.</p>
        <table>
            <tbody>
                <tr><td style="width:38%;color:var(--texto-suave)">Nombre completo</td><td>{{ $orden->responsableNombreCompleto() ?: '—' }}</td></tr>
                <tr><td style="color:var(--texto-suave)">Cargo</td><td>{{ $orden->responsible_position ?: '—' }}</td></tr>
                <tr><td style="color:var(--texto-suave)">Correo</td><td>{{ $orden->responsible_email }}</td></tr>
                <tr><td style="color:var(--texto-suave)">Teléfono</td><td>{{ $orden->responsible_phone ?: '—' }}</td></tr>
                <tr><td style="color:var(--texto-suave)">Tipo de inscripción</td><td>{{ $orden->kind->label() }}</td></tr>
            </tbody>
        </table>
    </div>

    <div class="tarjeta">
        <h2>Entidad pagadora</h2>
        <p class="sub">A su nombre se emitirá la factura.</p>
        @if ($orden->payerEntity)
            <table>
                <tbody>
                    <tr><td style="width:38%;color:var(--texto-suave)">Razón social</td><td>{{ $orden->payerEntity->name }}</td></tr>
                    <tr><td style="color:var(--texto-suave)">RUT</td><td>{{ $orden->payerEntity->rut ?: '—' }}</td></tr>
                    <tr><td style="color:var(--texto-suave)">Dirección</td><td>{{ $orden->payerEntity->address ?: '—' }}</td></tr>
                    <tr><td style="color:var(--texto-suave)">Correo de facturación</td><td>{{ $orden->payerEntity->billing_email ?: '—' }}</td></tr>
                </tbody>
            </table>
        @else
            <div class="vacio">Falta registrar la entidad pagadora.</div>
        @endif
    </div>

    @if ($orden->establishments->isNotEmpty())
        <div class="tarjeta">
            <h2>Establecimientos</h2>
            @if ($orden->establishments->count() > 1)
                <p class="sub">Cada colegio queda como su propia inscripción, con su propio pago y su propia factura.</p>
            @endif
            <table>
                <thead>
                    <tr>
                        <th>Establecimiento</th><th>RBD</th>
                        <th style="text-align:right">Participantes</th>
                        <th style="text-align:right">Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($orden->establishments as $establecimiento)
                        @php
                            $participantesDelColegio = $orden->participants->where('establishment_id', $establecimiento->id);
                            $subtotalDelColegio = $participantesDelColegio->sum(fn ($p) => $p->accessType?->precioVigente() ?? 0);
                        @endphp
                        <tr>
                            <td>{{ $establecimiento->name }}</td>
                            <td>{{ $establecimiento->rbd ?: '—' }}</td>
                            <td style="text-align:right">{{ $participantesDelColegio->count() }}</td>
                            <td style="text-align:right">${{ number_format($subtotalDelColegio, 0, ',', '.') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <div class="tarjeta">
        <h2>Participantes y valores</h2>
        <table>
            <thead>
                <tr><th>Participante</th><th>Establecimiento</th><th>Acceso</th><th style="text-align:right">Valor</th></tr>
            </thead>
            <tbody>
                @foreach ($orden->participants as $participante)
                    <tr>
                        <td>
                            <strong>{{ $participante->nombre_completo }}</strong>
                            @if ($participante->rut)<div class="ayuda">{{ $participante->rut }}</div>@endif
                        </td>
                        <td>{{ $participante->establishment?->name ?: '—' }}</td>
                        <td><span class="chip">{{ $participante->accessType?->name }}</span></td>
                        <td style="text-align:right">${{ number_format($participante->accessType?->precioVigente() ?? 0, 0, ',', '.') }}</td>
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
                <td style="color:var(--exito)">{{ $orden->etiquetaDeDescuento() }}</td>
                <td style="text-align:right;color:var(--exito)">
                    -${{ number_format($orden->calcularDescuento(), 0, ',', '.') }}
                </td>
            </tr>
        </tbody>
    </table>
@endif

        <div id="codigo" style="margin:16px 0 4px">
            @if ($orden->discountCode)
                <div class="aviso aviso-info" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
                    <span>Código aplicado: <strong>{{ $orden->discountCode->formateado() }}</strong></span>
                    <form method="POST" action="{{ route('inscripcion.codigo.quitar', ['token' => $token]) }}" style="margin:0">
                        <button type="submit" class="btn btn-secundario">Quitar código</button>
                    </form>
                </div>
            @else
                <form method="POST" action="{{ route('inscripcion.codigo.aplicar', ['token' => $token]) }}">
                    <label for="codigo-descuento">¿Tienes un código de descuento?</label>
                    <div style="display:flex;gap:8px;flex-wrap:wrap">
                        <input type="text" id="codigo-descuento" name="codigo" maxlength="24"
                               value="{{ $codigoEscrito ?? '' }}" autocomplete="off" autocapitalize="characters"
                               style="flex:1;min-width:180px;text-transform:uppercase">
                        <button type="submit" class="btn btn-secundario">Aplicar</button>
                    </div>
                </form>
                @if ($errorDeCodigo ?? null)
                    <div class="aviso aviso-error" style="margin-top:10px">{{ $errorDeCodigo }}</div>
                @endif
            @endif
        </div>

        <div class="total">
            <span>Total a pagar</span>
            <span class="monto">${{ number_format($orden->calcularTotal(), 0, ',', '.') }}</span>
        </div>
    </div>

    <div class="tarjeta">
        <h2>Condiciones</h2>
        <p class="sub">
            Al confirmar se generará tu número de inscripción y se reservarán los cupos por
            {{ $event->reservation_duration_value }} {{ mb_strtolower($event->reservation_duration_unit->label()) }}.
            Recibirás las instrucciones de pago por correo.
        </p>
        <p class="sub">
            Los datos de cada participante se usarán para su acreditación en el evento.
            Las credenciales se liberan una vez confirmado el pago.
        </p>
    </div>

    @if ($errorDeConfirmacion ?? null)
        <div class="aviso aviso-error">
            {{ $errorDeConfirmacion }}
            @include('publico._contacto', ['evento' => $event])
        </div>
    @endif

    <form method="POST" action="{{ route('inscripcion.confirmar', ['token' => $token]) }}">
        <div class="acciones">
            <a href="{{ route('inscripcion.pagador', ['token' => $token]) }}" class="btn btn-secundario">Volver a facturación</a>
            <button type="submit" class="btn btn-primario"
                data-enviando-texto="Confirmando…">Confirmar inscripción y recibir instrucciones de pago</button>
        </div>
    </form>
@endsection
