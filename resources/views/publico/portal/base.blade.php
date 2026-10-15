{{--
    Marco del portal del cliente: columna lateral con el contacto (y los
    colegios, si son varios), pestañas libres y la tarjeta de estado de la
    orden. Cada pestaña llena la sección `pestana`.
--}}
@extends('publico.layout')
@section('titulo', 'Mi inscripción')
@section('subtitulo', $varios ? $colegios->count().' establecimientos' : ($orden->number ? 'Inscripción '.$orden->number : 'Completa tu inscripción'))
@section('envoltura', 'ancha')

@section('contenido')
    @php
        $colegioActual = $orden->establishments->first()?->id;
        // El colegio viaja en la URL solo cuando hay varios: con uno no hay nada que elegir.
        $enlace = fn (string $nombre, array $extra = []): string => route(
            'inscripcion.portal.'.$nombre,
            ['token' => $token] + ($varios && $colegioActual ? ['colegio' => $colegioActual] : []) + $extra,
        );
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
                                <a href="{{ route('inscripcion.portal.'.$pestana, ['token' => $token, 'colegio' => $colegio->establishments->first()?->id]) }}"
                                   @class(['lado-item', 'activo' => $esActual])
                                   @if ($esActual) aria-current="page" @endif>
                                    <span class="lado-item-nombre">{{ $colegio->establishments->first()?->name ?: 'Establecimiento' }}</span>
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
                    <p class="lado-nota">Este correo no se responde.</p>
                </div>
            @endif
        </nav>

        <div class="principal">
            @php
                // Un borrador se presenta como inscripción nueva: "Borrador" es
                // palabra del sistema. Su insignia dice si ya se puede confirmar.
                $listaParaConfirmar = $orden->esBorrador() && app(\App\Actions\ConfirmarOrden::class)->faltantes($orden) === [];
            @endphp
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
        </div>
    </div>
@endsection
