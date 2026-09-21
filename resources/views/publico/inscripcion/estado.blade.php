@extends('publico.layout')
@section('titulo', 'Mi inscripción')
@section('subtitulo', $colegios->count() > 1 ? $colegios->count().' establecimientos' : 'Inscripción '.$orden->number)
@section('envoltura', 'ancha')

@section('contenido')
    @php
        $varios = $colegios->count() > 1;
        $colegioActual = $orden->establishments->first()?->id;
    @endphp

    <div class="columnas">
        {{--
            Menú de la inscripción. Con varios colegios esto reemplaza al
            listado apilado: antes la pantalla repetía pasos, pago, detalle y
            participantes por cada colegio y había que bajar metros para llegar
            al tercero. Acá se ve uno a la vez y el menú dice de un vistazo cuál
            debe plata.
        --}}
        <nav class="lado" aria-label="Tu inscripción">
            @if ($varios)
                <div class="lado-caja">
                    <p class="lado-titulo">Tu inscripción</p>
                    <ul class="lado-lista">
                        @foreach ($colegios as $colegio)
                            @php $esActual = $colegio->is($orden); @endphp
                            <li>
                                <a href="{{ route('inscripcion.estado', ['token' => $token, 'colegio' => $colegio->establishments->first()?->id]) }}"
                                   @class(['lado-item', 'activo' => $esActual])
                                   @if ($esActual) aria-current="page" @endif>
                                    <span class="lado-item-nombre">{{ $colegio->establishments->first()?->name ?: 'Establecimiento' }}</span>
                                    <span class="lado-item-estado">
                                        @if ($colegio->saldo() > 0)
                                            Debe ${{ number_format($colegio->saldo(), 0, ',', '.') }}
                                        @else
                                            Pagado
                                        @endif
                                    </span>
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
            @include('publico.inscripcion._colegio', [
                'orden' => $orden,
                'token' => $token,
                'event' => $event,
                'varios' => $varios,
                'activo' => true,
                'valores' => $valores,
                'errores' => $errores,
                'errorDeComprobante' => $errorDeComprobante,
            ])

            {{-- Cada colegio va en su propia inscripción: desde aquí se empieza la siguiente. --}}
            <div class="tarjeta" id="otro">
                <h2>¿Inscribes otro establecimiento?</h2>
                <p class="sub">Otro establecimiento se inscribe por separado, con su propio pago y su propia factura.</p>
                @if (request()->boolean('enviado'))
                    <div class="aviso aviso-exito">
                        Te enviamos un correo a {{ $orden->responsible_email }} con el enlace para inscribir
                        el siguiente establecimiento. Esta inscripción queda como está.
                    </div>
                @else
                    <form method="POST" action="{{ route('inscripcion.otro', ['token' => $token]) }}">
                        <input type="hidden" name="colegio" value="{{ $colegioActual }}">
                        <div class="acciones">
                            <button type="submit" class="btn btn-secundario" data-enviando-texto="Enviando…">Inscribir otro establecimiento</button>
                        </div>
                    </form>
                @endif
            </div>
        </div>
    </div>
@endsection
