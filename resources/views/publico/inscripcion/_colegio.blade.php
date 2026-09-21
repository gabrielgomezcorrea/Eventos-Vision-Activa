{{--
    Detalle de un colegio del conjunto. Con un solo colegio esto es toda la
    pantalla; con varios, se repite uno de estos bloques por cada uno, cada
    cual con sus propias acciones apuntando a su propia orden.
--}}
@php
    $parametros = $varios
        ? ['token' => $token, 'establishment' => $orden->establishments->first()?->id]
        : ['token' => $token];
    $pasoActual = $orden->payment_status->liberaCredenciales() ? 4 : 3;
    $proceso = ['Programa', 'Inscripción', 'Pago', 'Credenciales'];
@endphp

@if ($varios)
    <h2 style="margin:0 0 4px">{{ $orden->establishments->first()?->name ?: 'Establecimiento' }}</h2>
@endif

<ol class="pasos">
    @foreach ($proceso as $etiqueta)
        <li class="{{ $loop->iteration === $pasoActual ? 'actual' : ($loop->iteration < $pasoActual ? 'hecho' : '') }}">
            {{ $loop->iteration }}. {{ $etiqueta }}
        </li>
    @endforeach
</ol>

<div class="tarjeta">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:14px;flex-wrap:wrap">
        <div>
            <h2>Inscripción {{ $orden->number }}</h2>
            <p class="sub" style="margin:0">
                Confirmada el {{ $orden->confirmed_at?->translatedFormat('d \d\e F \d\e Y, H:i') }}
            </p>
        </div>
        <div style="text-align:right">
            <span class="chip" style="background:var(--aviso-suave);color:#8A4704">{{ $orden->status->label() }}</span>
            <div style="margin-top:6px">
                <span class="chip">{{ $orden->payment_status->label() }}</span>
            </div>
        </div>
    </div>
</div>

@php
    $pago = $orden->pagoVigente();
    $observacion = $pago?->reviews->firstWhere('action', \App\Enums\PaymentReviewAction::Observar);
@endphp

@if ($activo && ($errorDeComprobante ?? null))
    <div class="aviso aviso-error">
        {{ $errorDeComprobante }}
        @include('publico._contacto', ['evento' => $event])
    </div>
@endif

@if ($pago && $pago->status === \App\Enums\PaymentStatus::EnValidacion)
    <div class="tarjeta">
        <h2>Comprobante en validación</h2>
        <p class="sub">
            Recibimos tu comprobante el {{ $pago->created_at->translatedFormat('d \d\e F, H:i') }}.
            Contabilidad lo está revisando. Mientras tanto, tus cupos siguen reservados.
        </p>
        <table>
            <tbody>
                <tr><td style="width:38%;color:var(--texto-suave)">Monto informado</td><td>${{ number_format($pago->amount, 0, ',', '.') }}</td></tr>
                <tr><td style="color:var(--texto-suave)">Fecha de transferencia</td><td>{{ $pago->paid_on?->format('d-m-Y') ?: '—' }}</td></tr>
                <tr><td style="color:var(--texto-suave)">Banco de origen</td><td>{{ $pago->bank_name ?: '—' }}</td></tr>
            </tbody>
        </table>
    </div>
@endif

@if ($orden->payment_status === \App\Enums\PaymentStatus::Observado && $observacion)
    <div class="tarjeta" style="border-color:#F9AB55">
        <h2>Necesitamos una aclaración</h2>
        <p class="sub">Revisamos tu comprobante y hay algo que corregir:</p>
        <div class="aviso" style="background:var(--aviso-suave);color:#8A4704">{{ $observacion->comment }}</div>
        <p class="sub" style="margin:12px 0 0">
            Tus cupos siguen reservados. Envía un comprobante corregido aquí abajo.
        </p>
    </div>
@endif

@if ($orden->payment_status === \App\Enums\PaymentStatus::Aprobado)
    <div class="tarjeta" style="border-color:#BFE0C6">
        <h2>Pago confirmado</h2>
        <p class="sub" style="margin:0">
            Tus cupos quedan asegurados. Te haremos llegar las credenciales de cada
            participante antes del evento.
        </p>
    </div>
@endif

@if ($orden->payment_status->liberaCredenciales() && $orden->tickets()->vigentes()->exists())
    <div class="tarjeta">
        <h2>Credenciales</h2>
        <p class="sub">
            Cada participante recibió la suya por correo. Desde aquí puedes verlas todas,
            imprimirlas o descargar el código QR de cada uno para reenviarlo.
        </p>
        <div class="acciones">
            <a class="btn btn-primario" href="{{ route('inscripcion.credenciales', $parametros) }}">
                Ver las credenciales
            </a>
        </div>
    </div>
@endif

{{-- Lo que el cliente vino a hacer va primero: transferir y avisar que
     transfirió. Antes estaba al final, debajo del detalle, y obligaba a
     bajar buscando dónde subir el comprobante. --}}
@if ($orden->admiteComprobante() || ($orden->status === \App\Enums\OrderStatus::Reservada && ! $orden->payment_status->liberaCredenciales()))
    <div class="tarjeta">
    @if ($orden->status === \App\Enums\OrderStatus::Reservada && ! $orden->payment_status->liberaCredenciales())
        <h3 class="paso">1. Transfiere @if ($orden->pagado() > 0)el saldo @else el total @endif</h3>
        @if ($orden->pagado() > 0)
            <table style="margin-bottom:14px">
                <tbody>
                    <tr><td style="width:38%;color:var(--texto-suave)">Total</td><td>${{ number_format($orden->total, 0, ',', '.') }}</td></tr>
                    <tr><td style="color:var(--texto-suave)">Pagado</td><td>${{ number_format($orden->pagado(), 0, ',', '.') }}</td></tr>
                    <tr><td style="color:var(--texto-suave)"><strong>Saldo</strong></td><td><strong>${{ number_format($orden->saldo(), 0, ',', '.') }}</strong></td></tr>
                </tbody>
            </table>
        @endif
        <p class="sub">
            Los cupos están reservados hasta el
            <strong>{{ $orden->reserved_until?->translatedFormat('l d \d\e F \d\e Y, H:i') }}</strong>.
        </p>

        @if ($event->bank_account_number)
            <table>
                <tbody>
                    <tr><td style="width:38%;color:var(--texto-suave)">Titular</td><td>{{ $event->bank_holder_name }}</td></tr>
                    <tr><td style="color:var(--texto-suave)">RUT</td><td>{{ $event->bank_holder_rut }}</td></tr>
                    <tr><td style="color:var(--texto-suave)">Banco</td><td>{{ $event->bank_name }}</td></tr>
                    <tr><td style="color:var(--texto-suave)">Tipo de cuenta</td><td>{{ $event->bank_account_type }}</td></tr>
                    <tr><td style="color:var(--texto-suave)">N° de cuenta</td><td>{{ $event->bank_account_number }}</td></tr>
                    <tr><td style="color:var(--texto-suave)">Referencia</td><td><strong>{{ $orden->number }}</strong></td></tr>
                </tbody>
            </table>
        @else
            <div class="vacio">Nos pondremos en contacto contigo con los datos para la transferencia.</div>
        @endif

        @if ($event->payment_instructions)
            <p class="sub" style="margin-top:14px">{{ $event->payment_instructions }}</p>
        @endif
    @endif

    @if ($orden->admiteComprobante())
        <form method="POST" action="{{ route('inscripcion.comprobante', $parametros) }}"
              enctype="multipart/form-data" novalidate>
            <h3 class="paso">2. Sube el comprobante</h3>
            <p class="sub">Aceptamos PDF o imagen, hasta 10 MB.</p>

            <div class="rejilla">
                <div>
                    <label for="amount-{{ $orden->id }}">Monto transferido</label>
                    <input type="text" inputmode="numeric" id="amount-{{ $orden->id }}" name="amount"
                           value="{{ $valores['amount'] ?? $orden->saldo() }}"
                           @if ($errores->has('amount')) aria-invalid="true" @endif>
                    @if ($errores->has('amount'))
                        <div class="error-campo">{{ $errores->first('amount') }}</div>
                    @else
                        <div class="ayuda">
                            @if ($orden->pagado() > 0)
                                Saldo pendiente: ${{ number_format($orden->saldo(), 0, ',', '.') }}. Puedes pagarlo en partes.
                            @else
                                Total de la inscripción: ${{ number_format($orden->total, 0, ',', '.') }}. Puedes pagarlo en partes.
                            @endif
                        </div>
                    @endif
                </div>

                <div>
                    <label for="paid_on-{{ $orden->id }}">Fecha de la transferencia</label>
                    <input type="date" id="paid_on-{{ $orden->id }}" name="paid_on" value="{{ $valores['paid_on'] ?? '' }}"
                           max="{{ now()->format('Y-m-d') }}"
                           @if ($errores->has('paid_on')) aria-invalid="true" @endif>
                    @if ($errores->has('paid_on'))
                        <div class="error-campo">{{ $errores->first('paid_on') }}</div>
                    @endif
                </div>

                <div>
                    <label for="bank_name-{{ $orden->id }}">Banco de origen</label>
                    <input type="text" id="bank_name-{{ $orden->id }}" name="bank_name" value="{{ $valores['bank_name'] ?? '' }}"
                           @if ($errores->has('bank_name')) aria-invalid="true" @endif>
                    @if ($errores->has('bank_name'))
                        <div class="error-campo">{{ $errores->first('bank_name') }}</div>
                    @endif
                </div>

                <div>
                    <label for="payer_name-{{ $orden->id }}">Nombre de quien pagó</label>
                    <input type="text" id="payer_name-{{ $orden->id }}" name="payer_name" value="{{ $valores['payer_name'] ?? '' }}"
                           @if ($errores->has('payer_name')) aria-invalid="true" @endif>
                    @if ($errores->has('payer_name'))
                        <div class="error-campo">{{ $errores->first('payer_name') }}</div>
                    @endif
                </div>

                <div>
                    <label for="payer_rut-{{ $orden->id }}">RUT de quien pagó</label>
                    <input type="text" id="payer_rut-{{ $orden->id }}" name="payer_rut" value="{{ $valores['payer_rut'] ?? '' }}"
                           data-rut maxlength="12" autocomplete="off" placeholder="12.345.678-9"
                           @if ($errores->has('payer_rut')) aria-invalid="true" @endif>
                    @if ($errores->has('payer_rut'))
                        <div class="error-campo">{{ $errores->first('payer_rut') }}</div>
                    @endif
                </div>

                <div>
                    <label for="proof-{{ $orden->id }}">Comprobante</label>
                    <input type="file" id="proof-{{ $orden->id }}" name="proof" accept=".pdf,.jpg,.jpeg,.png,.webp"
                           data-max-mb="10"
                           @if ($errores->has('proof')) aria-invalid="true" @endif>
                    @if ($errores->has('proof'))
                        <div class="error-campo">{{ $errores->first('proof') }}</div>
                    @endif
                </div>

                <div class="ancho">
                    <label for="notes-{{ $orden->id }}">Observaciones <span class="opcional">(opcional)</span></label>
                    <input type="text" id="notes-{{ $orden->id }}" name="notes" value="{{ $valores['notes'] ?? '' }}">
                </div>

                <div class="ancho">
                    <label for="billing_notes-{{ $orden->id }}">Observaciones para la factura <span class="opcional">(opcional)</span></label>
                    <input type="text" id="billing_notes-{{ $orden->id }}" name="billing_notes" value="{{ $valores['billing_notes'] ?? '' }}"
                           placeholder="Por ejemplo: emitir a nombre de la fundación">
                </div>
            </div>

            <div class="acciones">
                <button type="submit" class="btn btn-primario"
                        data-enviando-texto="Enviando comprobante…">Enviar comprobante</button>
            </div>
        </form>
    @endif
    </div>
@endif

@if ($orden->payments->isNotEmpty())
    <div class="tarjeta">
        <h2>Comprobantes enviados</h2>
        <table>
            <thead><tr><th>Fecha</th><th>Monto</th><th>Estado</th></tr></thead>
            <tbody>
                @foreach ($orden->payments as $abono)
                    <tr>
                        <td>{{ $abono->created_at->translatedFormat('d \d\e F, H:i') }}</td>
                        <td>${{ number_format($abono->amount, 0, ',', '.') }}</td>
                        <td>{{ $abono->status->label() }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

<div class="tarjeta">
    <h2>Detalle</h2>
    <table>
        <tbody>
            <tr><td style="width:38%;color:var(--texto-suave)">Responsable</td><td>{{ $orden->responsableNombreCompleto() }}</td></tr>
            <tr><td style="color:var(--texto-suave)">Entidad pagadora</td><td>{{ $orden->payerEntity?->name ?: '—' }}</td></tr>
            <tr><td style="color:var(--texto-suave)">Participantes</td><td>{{ $orden->participantesVigentes->count() }}</td></tr>
        </tbody>
    </table>

    <table style="margin-top:14px">
        <thead><tr><th>Participante</th><th>Acceso</th><th style="text-align:right">Valor</th></tr></thead>
        <tbody>
            @foreach ($orden->participantesVigentes as $participante)
                <tr>
                    <td>{{ $participante->nombre_completo }}</td>
                    <td><span class="chip">{{ $participante->accessType?->name }}</span></td>
                    <td style="text-align:right">${{ number_format($participante->unit_price, 0, ',', '.') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($orden->discount_amount > 0)
        <table>
            <tbody>
                <tr>
                    <td style="color:var(--texto-suave)">Subtotal</td>
                    <td style="text-align:right">${{ number_format($orden->subtotal, 0, ',', '.') }}</td>
                </tr>
                <tr>
                    <td style="color:var(--exito)">{{ $orden->discount_label }}</td>
                    <td style="text-align:right;color:var(--exito)">
                        -${{ number_format($orden->discount_amount, 0, ',', '.') }}
                    </td>
                </tr>
            </tbody>
        </table>
    @endif

    <div class="total">
        <span>Total</span>
        <span class="monto">${{ number_format($orden->total, 0, ',', '.') }}</span>
    </div>
</div>

@if ($orden->status === \App\Enums\OrderStatus::Vencida)
    <div class="aviso-texto">
        La reserva de cupos venció y los cupos fueron liberados. Si aún deseas participar, contáctanos:
        @include('publico._contacto', ['evento' => $event])
    </div>
@endif
