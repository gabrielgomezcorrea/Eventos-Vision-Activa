{{--
    Marco del portal del cliente: columna lateral con el contacto (y los
    colegios, si son varios), pestañas libres y la tarjeta de estado de la
    orden. Cada pestaña llena la sección `pestana`.
--}}
@extends('publico.layout')
@section('titulo', 'Mi inscripción')
@section('subtitulo', $varios ? $colegios->count().' establecimientos' : ($orden->number ? 'Inscripción '.$orden->number : 'Inscripción a '.$event->name))
@section('envoltura', 'ancha')

@section('contenido')
    @php
        // La inscripción viaja en la URL solo cuando hay varias: con una no hay nada que elegir.
        $enlace = fn (string $nombre, array $extra = []): string => route(
            'inscripcion.portal.'.$nombre,
            ['token' => $token] + ($varios ? ['inscripcion' => $orden->public_id] : []) + $extra,
        );
        // Un borrador se presenta como inscripción nueva: "Borrador" es
        // palabra del sistema. Su insignia dice si ya se puede confirmar.
        $listaParaConfirmar = $orden->esBorrador() && app(\App\Actions\ConfirmarOrden::class)->faltantes($orden) === [];
        // Dos inscripciones del mismo colegio se distinguen por su folio en el menú.
        $colegiosRepetidos = $colegios
            ->groupBy(fn ($c) => $c->establishments->first()?->id)
            ->filter(fn ($grupo) => $grupo->count() > 1)
            ->keys();
        $pestanas = ['programa' => 'Programa', 'inscripcion' => 'Inscripción', 'pago' => 'Pago', 'credenciales' => 'Credenciales'];
    @endphp

    <div class="columnas">
        <nav class="lado" aria-label="Tu inscripción">
            @if ($varios)
                <div class="lado-caja">
                    <p class="lado-titulo">Tu inscripción</p>
                    <ul class="lado-lista">
                        @foreach ($colegios as $colegio)
                            @php $esActual = $colegio->is($orden); @endphp
                            <li>
                                <a href="{{ route('inscripcion.portal.'.$pestana, ['token' => $token, 'inscripcion' => $colegio->public_id]) }}"
                                   @class(['lado-item', 'activo' => $esActual])
                                   @if ($esActual) aria-current="page" @endif>
                                    <span class="lado-item-nombre">{{ $colegio->establishments->first()?->name ?: 'Establecimiento' }}</span>
                                    @if ($colegiosRepetidos->contains($colegio->establishments->first()?->id))
                                        <span class="lado-item-estado">{{ $colegio->esBorrador() ? 'Nueva, sin confirmar' : 'Inscripción '.$colegio->number }}</span>
                                    @endif
                                    {{-- El estado del pago de cada colegio, a la vista: quien envió
                                         un comprobante lo ve aquí sin entrar a buscarlo. --}}
                                    @php
                                        $estadoPago = $colegio->payment_status;
                                        $colorPago = match ($estadoPago) {
                                            \App\Enums\PaymentStatus::Aprobado => 'chip-exito',
                                            \App\Enums\PaymentStatus::Rechazado => 'chip-error',
                                            \App\Enums\PaymentStatus::Observado => 'chip-aviso',
                                            default => '',
                                        };
                                    @endphp
                                    <span class="lado-item-estado">
                                        @if ($colegio->esBorrador())
                                            Sin confirmar
                                        @elseif ($colegio->saldo() > 0)
                                            Debe ${{ number_format($colegio->saldo(), 0, ',', '.') }}
                                        @else
                                            Pagado
                                        @endif
                                    </span>
                                    @if (! $colegio->esBorrador() && $estadoPago !== \App\Enums\PaymentStatus::Pendiente)
                                        <span class="chip {{ $colorPago }}" style="margin-top:4px">{{ $estadoPago === \App\Enums\PaymentStatus::EnValidacion ? 'Comprobante en revisión' : $estadoPago->label() }}</span>
                                    @endif
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- A quién llamar: siempre a la vista, no al fondo de la página. --}}
            @if ($event->tieneContacto())
                <div class="lado-caja">
                    <p class="lado-titulo">Contáctanos</p>
                    @include('publico._contacto', ['evento' => $event, 'plano' => true])
                </div>
            @endif

            {{-- Dónde transferir, siempre a la vista en todas las pestañas mientras
                 falte pagar (pedido del jefe, 28/10/2026). Orden del menú:
                 contacto, datos para transferir, código de descuento. --}}
            @php
                // Desde el borrador (pedido del jefe, 28/10/2026): la gente quiere ver
                // a dónde se paga mientras completa. Sin monto hasta que haya total.
                $debeTransferir = $orden->kind !== \App\Enums\OrderKind::Invitado
                    && ! in_array($orden->status, [\App\Enums\OrderStatus::Cancelada, \App\Enums\OrderStatus::Vencida], true)
                    && ! $orden->payment_status->liberaCredenciales();
                $montoLado = $orden->esBorrador() ? $orden->calcularTotal() : ($orden->pagado() > 0 ? $orden->saldo() : (int) $orden->total);
            @endphp
            @if ($debeTransferir && $event->bank_account_number)
                <div class="lado-caja">
                    <p class="lado-titulo">Datos para transferir</p>
                    @include('publico.portal._datos-bancarios', [
                        'apilado' => true,
                        ...($montoLado > 0 ? ['monto' => $montoLado] : []),
                        'referencia' => $orden->number ?? 'Se genera al confirmar',
                    ])
                </div>
            @endif
            {{-- Código de descuento: arriba y a la vista mientras se completa la
                 inscripción; con todo completo se oculta. --}}
            @if ($orden->esBorrador() && ! $listaParaConfirmar && $orden->kind !== \App\Enums\OrderKind::Invitado)
                @include('publico.portal._codigo-lado')
            @endif

            {{-- Los comprobantes que subió este colegio, con enlace para abrirlos. --}}
            @if ($orden->payments->isNotEmpty())
                <div class="lado-caja">
                    <p class="lado-titulo">Tus comprobantes</p>
                    @foreach ($orden->payments as $abono)
                        @php
                            [$textoAbono, $claseAbono] = $orden->abonoReemplazado($abono) ? ['Reemplazado', ''] : match ($abono->status) {
                                \App\Enums\PaymentStatus::Aprobado => ['Aprobado', 'chip-exito'],
                                \App\Enums\PaymentStatus::Rechazado => ['Rechazado', 'chip-error'],
                                \App\Enums\PaymentStatus::Observado => ['Observado', 'chip-aviso'],
                                default => ['En revisión', ''],
                            };
                        @endphp
                        <p style="margin:0 0 8px;font-size:.9rem">
                            <strong>${{ number_format($abono->amount, 0, ',', '.') }}</strong>
                            <span class="chip {{ $claseAbono }}">{{ $textoAbono }}</span>
                            <a href="{{ route('inscripcion.comprobante.ver', ['token' => $token, 'payment' => $abono->id]) }}" target="_blank" rel="noopener">Ver</a>
                        </p>
                    @endforeach
                </div>
            @endif

        </nav>

        <div class="principal">
            <nav class="pestanas" aria-label="Secciones de tu inscripción">
                @foreach ($pestanas as $clave => $etiqueta)
                    @if ($clave === 'pago' && $listaParaConfirmar)
                        {{-- Con todo completo, pasar a Pago confirma: toma los cupos,
                             genera el número y separa los colegios. Es un POST y no
                             un enlace porque las vistas previas de correos y
                             navegadores abren enlaces solas, y eso no puede
                             confirmar una inscripción por nadie. --}}
                        <form method="POST" action="{{ route('inscripcion.confirmar', ['token' => $token]) }}" style="display:contents">
                            <button type="submit" @class(['pestana', 'activa' => $clave === $pestana])
                                    data-enviando-texto="Confirmando…">{{ $loop->iteration }}. {{ $etiqueta }}</button>
                        </form>
                    @else
                        <a href="{{ $enlace($clave) }}"
                           @class(['pestana', 'activa' => $clave === $pestana])
                           @if ($clave === $pestana) aria-current="page" @endif>{{ $loop->iteration }}. {{ $etiqueta }}</a>
                    @endif
                @endforeach
            </nav>
            <div class="tarjeta estado-orden">
                <div>
                    {{-- Con varios colegios se ve uno a la vez: su nombre va primero y
                         en grande, para que no haya duda de cuál se está pagando. --}}
                    @if ($varios && ! $orden->esBorrador())
                        <p class="etiqueta-seccion" style="margin:0 0 2px">Establecimiento</p>
                        <h2 style="font-size:1.25rem">{{ $orden->establishments->first()?->name }}</h2>
                        <p style="margin:2px 0 0;font-weight:600">Inscripción {{ $orden->number }}</p>
                    @else
                        <h2>{{ $orden->esBorrador() ? 'Nueva inscripción' : 'Inscripción '.$orden->number }}</h2>
                    @endif
                    <p class="sub" style="margin:4px 0 0">
                        @if ($orden->esBorrador())
                            Sin confirmar aún
                        @else
                            Confirmada el {{ $orden->confirmed_at?->translatedFormat('d \\d\\e F \\d\\e Y, H:i') }}
                        @endif
                    </p>
                </div>
                <div class="chips">
                    @if ($orden->esBorrador())
                        <span @class(['chip', 'chip-exito' => $listaParaConfirmar, 'chip-error' => ! $listaParaConfirmar])>{{ $listaParaConfirmar ? 'Lista para confirmar' : 'Incompleta' }}</span>
                    @else
                        <span class="chip chip-aviso">{{ $orden->status->label() }}</span>
                        <span @class(['chip', 'chip-exito' => $orden->payment_status->liberaCredenciales()])>{{ $orden->payment_status->label() }}</span>
                    @endif
                </div>
            </div>

            @yield('pestana')

            {{-- Siguiente paso: nunca queda sin saber qué sigue. Las pestañas
                 siguen libres; esto es solo el camino más corto. --}}
            @if ($pestana === 'programa')
                <div class="acciones acciones-derecha"><a class="btn btn-siguiente" href="{{ $enlace('inscripcion') }}">Siguiente: Inscripción</a></div>
            @elseif ($pestana === 'inscripcion')
                <div class="acciones acciones-derecha">
                    @if ($listaParaConfirmar)
                        {{-- Pasar a Pago confirma, igual que la pestaña: es un POST. --}}
                        <form method="POST" action="{{ route('inscripcion.confirmar', ['token' => $token]) }}">
                            <button type="submit" class="btn btn-siguiente" data-enviando-texto="Confirmando…">Siguiente: Pago</button>
                        </form>
                    @else
                        <a class="btn btn-siguiente" href="{{ $enlace('pago') }}">Siguiente: Pago</a>
                    @endif
                </div>
            @elseif ($pestana === 'pago' && ! $orden->esBorrador() && $orden->payment_status->liberaCredenciales())
                <div class="acciones acciones-derecha"><a class="btn btn-siguiente" href="{{ $enlace('credenciales') }}">Siguiente: Credenciales</a></div>
            @endif
        </div>
    </div>
@endsection
