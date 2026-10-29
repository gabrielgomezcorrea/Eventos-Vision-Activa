@extends('publico.portal.base')

{{--
    Pestaña Pago, igual a la referencia (docs/design-references/3 y 4):
    transferir, subir el comprobante, detalle y otro establecimiento. Una
    inscripción sin confirmar se ve igual, con "-" donde aún no hay dato, y
    "Enviar comprobante" bloqueado con lo primero que falta al lado. Cuando ya
    no falta nada, eso que falta es confirmar: el folio que va como referencia
    de la transferencia y la reserva de cupos nacen ahí.
--}}
@section('pestana')
@php
    $borrador = $orden->esBorrador();
    $faltantes = $faltantes ?? [];
    $parametros = $varios
        ? ['token' => $token, 'inscripcion' => $orden->public_id]
        : ['token' => $token];
    $pago = $orden->pagoVigente();
    $observacion = $pago?->reviews->firstWhere('action', \App\Enums\PaymentReviewAction::Observar);
    $aprobado = $orden->payment_status->liberaCredenciales();
    $muestraTransferencia = $borrador || ($orden->status === \App\Enums\OrderStatus::Reservada && ! $aprobado);
    $admiteComprobante = ! $borrador && $orden->admiteComprobante();

    $total = $borrador ? $orden->calcularTotal() : (int) $orden->total;
    // Con varios colegios cada paso dice de cuál es.
    $deColegio = ($varios && ! $borrador) ? ' - '.$orden->establishments->first()?->name : '';
    $sinParticipantes = $orden->participantesVigentes->isEmpty();
    // Lo primero que falta: se dice una sola cosa a la vez.
    $primeraFalta = $faltantes[0] ?? null;
@endphp


@if (request('comprobante') === 'recibido' && ! ($errorDeComprobante ?? null))
    <div class="aviso aviso-exito" role="status">
        <strong>Se ha subido correctamente tu comprobante.</strong> Contabilidad lo revisará y te avisaremos por correo.
    </div>
@endif

{{-- Por qué el pago aún no se puede usar: una sola cosa, la primera que falta. --}}
@if ($borrador && $primeraFalta)
    <div class="aviso aviso-error" id="aviso-pago">
        Debes completar lo requerido en
        <a href="{{ route('inscripcion.portal.inscripcion', ['token' => $token]) }}#{{ $primeraFalta['bloque'] }}" class="enlace-aviso">Inscripción</a>
    </div>
@elseif ($borrador)
    <div class="aviso aviso-error" id="aviso-pago">
        Para habilitar el pago debes
        <button type="submit" form="confirmar-inscripcion" class="enlace enlace-aviso" data-enviando-texto="Confirmando…">confirmar tu inscripción</button>
    </div>
@endif

@if ($orden->status === \App\Enums\OrderStatus::Vencida)
    <div class="aviso aviso-error">
        La reserva de cupos venció y los cupos fueron liberados. Si aún deseas participar, contáctanos:
        @include('publico._contacto', ['evento' => $event])
    </div>
@endif

@if ($errorDeComprobante ?? null)
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
        <p class="sub" style="margin:12px 0 0">Tus cupos siguen reservados. Envía un comprobante corregido aquí abajo.</p>
    </div>
@endif

@if (! $borrador && $aprobado)
    <div class="tarjeta" style="border-color:#BFE0C6">
        <h2>Pago confirmado</h2>
        <p class="sub" style="margin:0">Tus cupos quedan asegurados. Las credenciales están en la pestaña Credenciales.</p>
    </div>
@endif

@if ($muestraTransferencia)
    <div class="tarjeta" id="transferencia">
        <h3 class="paso">1. Datos para transferir</h3>
        @php $conAbonos = ! $borrador && $orden->pagado() > 0; @endphp
        <p class="sub">
            Transfiere {{ $conAbonos ? 'el saldo' : 'el total' }}{{ $deColegio !== '' ? ' de '.$orden->establishments->first()?->name : '' }}:
            <strong>{{ ! $borrador ? '$'.number_format($conAbonos ? $orden->saldo() : $orden->total, 0, ',', '.') : ($sinParticipantes ? '$ -' : '$'.number_format($total, 0, ',', '.')) }}</strong>
        </p>
        @if (! $borrador && $orden->pagado() > 0)
            <table style="margin-bottom:14px">
                <tbody>
                    <tr><td style="width:38%;color:var(--texto-suave)">Total</td><td>${{ number_format($orden->total, 0, ',', '.') }}</td></tr>
                    <tr><td style="color:var(--texto-suave)">Pagado</td><td>${{ number_format($orden->pagado(), 0, ',', '.') }}</td></tr>
                    <tr><td style="color:var(--texto-suave)"><strong>Saldo</strong></td><td><strong>${{ number_format($orden->saldo(), 0, ',', '.') }}</strong></td></tr>
                </tbody>
            </table>
        @endif
        <p class="sub">
            Tus cupos están reservados hasta el
            <strong>{{ $orden->reserved_until?->translatedFormat('l d \d\e F \d\e Y, H:i') ?? '-' }}</strong>.
        </p>

        @if ($event->bank_account_number)
            @include('publico.portal._datos-bancarios', ['referencia' => $orden->number ?? '-'])
            <p class="ayuda" style="margin-top:10px">Indica el número de inscripción en el mensaje de la transferencia.</p>
        @else
            <div class="vacio">Nos pondremos en contacto contigo con los datos para la transferencia.</div>
        @endif

        @if ($event->payment_instructions)
            <p class="sub" style="margin-top:14px">{{ $event->payment_instructions }}</p>
        @endif

        <form method="POST" action="{{ $admiteComprobante ? route('inscripcion.comprobante', $parametros) : '#' }}"
              enctype="multipart/form-data" novalidate id="comprobante" data-validar-comprobante data-saldo="{{ $borrador ? 0 : $orden->saldo() }}">
            <h3 class="paso">2. Sube el comprobante</h3>
            @php
                // Con un comprobante en revisión no hay nada que llenar: se muestra
                // cuál está en revisión y el formulario vuelve cuando Contabilidad lo revisa.
                $enRevision = ! $borrador && ! $admiteComprobante && $orden->tieneAbonoEnRevision();
                $abonoEnRevision = $enRevision ? $orden->payments->firstWhere('status', \App\Enums\PaymentStatus::EnValidacion) : null;
            @endphp
            @if ($enRevision)
                <div class="aviso aviso-info">
                    <strong>Comprobante en revisión.</strong> Te avisaremos por correo cuando Contabilidad lo valide.
                    @if ($abonoEnRevision)
                        <br>Monto enviado: ${{ number_format($abonoEnRevision->amount, 0, ',', '.') }} ·
                        <a href="{{ route('inscripcion.comprobante.ver', ['token' => $token, 'payment' => $abonoEnRevision->id]) }}" target="_blank" rel="noopener">Ver</a>
                    @endif
                </div>
            @else
            <fieldset @disabled(! $borrador && ! $admiteComprobante) style="border:0;margin:0;padding:0;min-width:0">
                <div class="rejilla">
                    <div>
                        <label for="amount-{{ $orden->id }}">Monto transferido</label>
                        <input type="text" inputmode="numeric" id="amount-{{ $orden->id }}" name="amount"
                               value="{{ $valores['amount'] ?? ($borrador ? '' : $orden->saldo()) }}"
                               @if ($errores->has('amount')) aria-invalid="true" @endif>
                        @if ($errores->has('amount'))
                            <div class="error-campo">{{ $errores->first('amount') }}</div>
                        @else
                            <div class="ayuda">
                                @if (! $borrador && $orden->pagado() > 0)
                                    Saldo pendiente: ${{ number_format($orden->saldo(), 0, ',', '.') }}.
                                @else
                                    Total de la inscripción: {{ $sinParticipantes ? '$ -' : '$'.number_format($total, 0, ',', '.') }}.
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
                        <label for="proof-{{ $orden->id }}">Comprobante</label>
                        @if (filled($valores['proof_guardado'] ?? null))
                            {{-- Ya lo había subido: queda guardado y no se pide de nuevo. --}}
                            <input type="hidden" name="proof_guardado" value="{{ $valores['proof_guardado'] }}">
                            <div class="ayuda" style="margin:0 0 6px">{{ $valores['proof_nombre'] ?? 'comprobante' }}</div>
                        @endif
                        <input type="file" id="proof-{{ $orden->id }}" name="proof" accept=".pdf,.jpg,.jpeg,.png,.webp"
                               data-max-mb="5"
                               @if ($errores->has('proof')) aria-invalid="true" @endif>
                        @if ($errores->has('proof'))
                            <div class="error-campo">{{ $errores->first('proof') }}</div>
                        @else
                            <div class="ayuda">PDF, JPG, PNG o WEBP · máx. 5 MB</div>
                        @endif
                    </div>
                </div>
            </fieldset>

            <div class="acciones" style="align-items:center">
                @if ($borrador)
                    {{-- Se ve desactivado, pero al hacer clic sube a la alerta que
                         dice qué falta: un botón muerto no explica nada. --}}
                    <a href="#aviso-pago" class="btn btn-primario btn-bloqueado" aria-disabled="true">Enviar comprobante</a>
                @else
                    <button type="submit" class="btn btn-primario" @disabled(! $admiteComprobante)
                            data-enviando-texto="Enviando comprobante…">Enviar comprobante</button>
                @endif
            </div>
            @endif

            @if ($errorDeConfirmacion ?? null)
                <div class="aviso aviso-error" style="margin-top:14px">
                    {{ $errorDeConfirmacion }}
                    @include('publico._contacto', ['evento' => $event])
                </div>
            @endif
        </form>

        @if ($borrador)
            {{-- Va aparte porque un formulario no puede ir dentro de otro; el
                 enlace "confirmar tu inscripción" de arriba lo envía. --}}
            <form method="POST" action="{{ route('inscripcion.confirmar', ['token' => $token]) }}" id="confirmar-inscripcion"></form>
        @endif
    </div>
@endif

@if (! $borrador && $orden->payments->isNotEmpty())
    <div class="tarjeta">
        <h2>Comprobantes enviados</h2>
        <table>
            <thead><tr><th>Fecha</th><th>Monto</th><th>Estado</th></tr></thead>
            <tbody>
                @foreach ($orden->payments as $abono)
                    <tr>
                        <td>{{ $abono->created_at->translatedFormat('d \d\e F, H:i') }}</td>
                        <td>${{ number_format($abono->amount, 0, ',', '.') }}</td>
                        <td>{{ $orden->abonoReemplazado($abono) ? 'Reemplazado por un comprobante nuevo' : $abono->status->label() }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

<div class="tarjeta" id="detalle">
    <h2>Detalle</h2>
    <table>
        <tbody>
            <tr><td style="width:38%;color:var(--texto-suave)">Responsable</td><td>{{ $orden->responsableNombreCompleto() ?: '-' }}</td></tr>
            <tr><td style="color:var(--texto-suave)">Datos de facturación</td><td>{{ $orden->payerEntity?->name ?: '-' }}</td></tr>
            <tr><td style="color:var(--texto-suave)">Participantes</td><td>{{ $sinParticipantes ? '-' : $orden->participantesVigentes->count() }}</td></tr>
        </tbody>
    </table>

    <table style="margin-top:14px">
            <thead><tr><th>Participante</th><th>Acceso</th><th style="text-align:right">Valor</th></tr></thead>
            <tbody>
                @forelse ($orden->participantesVigentes as $participante)
                    <tr>
                        <td>{{ $participante->nombre_completo }}</td>
                        <td><span class="chip">{{ $participante->accessType?->name ?? '-' }}</span></td>
                        <td style="text-align:right">${{ number_format($borrador ? ($participante->accessType?->precioVigente() ?? 0) : $participante->unit_price, 0, ',', '.') }}</td>
                    </tr>
                @empty
                    <tr><td>-</td><td>-</td><td style="text-align:right">-</td></tr>
                @endforelse
            </tbody>
        </table>

    @php $descuento = $borrador ? $orden->calcularDescuento() : (int) $orden->discount_amount; @endphp
    @if ($descuento > 0)
        <table>
            <tbody>
                <tr>
                    <td style="color:var(--texto-suave)">Subtotal</td>
                    <td style="text-align:right">${{ number_format($borrador ? $orden->calcularSubtotal() : $orden->subtotal, 0, ',', '.') }}</td>
                </tr>
                <tr>
                    <td style="color:var(--exito)">{{ $borrador ? $orden->etiquetaDeDescuento() : $orden->discount_label }}</td>
                    <td style="text-align:right;color:var(--exito)">-${{ number_format($descuento, 0, ',', '.') }}</td>
                </tr>
            </tbody>
        </table>
    @endif

    <div class="total">
        <span>Total</span>
        <span class="monto">{{ $sinParticipantes ? '$ -' : '$'.number_format($total, 0, ',', '.') }}</span>
    </div>
</div>

{{--
    Revisa el formulario del comprobante antes de enviarlo: si falta un dato,
    lo marca ahí mismo sin recargar, y el archivo elegido sigue elegido. El
    servidor valida igual; esto solo evita perder el archivo por un descuido.
--}}
@include('publico.portal._inscribir-mas')

<script>
(function () {
    var form = document.querySelector('[data-validar-comprobante]');
    if (! form) { return; }

    function campo(nombre) { return form.querySelector('[name="' + nombre + '"]'); }

    function marcar(input, mensaje) {
        var envoltura = input.parentNode;
        var previo = envoltura.querySelector('[data-error-cliente]');
        if (previo) { previo.remove(); }
        if (! mensaje) { input.removeAttribute('aria-invalid'); return; }
        input.setAttribute('aria-invalid', 'true');
        var aviso = document.createElement('div');
        aviso.className = 'error-campo';
        aviso.setAttribute('data-error-cliente', '');
        aviso.textContent = mensaje;
        input.insertAdjacentElement('afterend', aviso);
    }

    form.addEventListener('submit', function (evento) {
        var saldo = Number(form.dataset.saldo || 0);
        var hoy = new Date().toISOString().slice(0, 10);
        var monto = campo('amount'), fecha = campo('paid_on'), archivo = campo('proof');
        var guardado = campo('proof_guardado');
        var montoNumero = Number(String(monto.value).replace(/\D/g, ''));

        var errores = [
            [monto, ! montoNumero ? 'Escribe el monto transferido.' : (saldo && montoNumero > saldo ? 'El monto no puede superar el saldo pendiente.' : '')],
            [fecha, ! fecha.value ? 'Indica la fecha de la transferencia.' : (fecha.value > hoy ? 'La fecha de transferencia no puede ser futura.' : '')],
            [archivo, ! (archivo.files && archivo.files.length) && ! guardado ? 'Adjunta el comprobante de la transferencia.' : ''],
        ];

        var primero = null;
        errores.forEach(function (par) {
            marcar(par[0], par[1]);
            if (par[1] && ! primero) { primero = par[0]; }
        });

        if (primero) {
            evento.preventDefault();
            evento.stopImmediatePropagation();
            primero.focus();
        }
    }, true);
})();
</script>
@endsection
