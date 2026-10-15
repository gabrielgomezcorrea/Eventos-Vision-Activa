@extends('publico.portal.base')

@section('pestana')
    @if ($adjuntos->isNotEmpty())
        <div class="tarjeta estado-orden">
            <div>
                <h2>Programa del evento</h2>
                @if ($adjuntos->count() > 1)
                    <p class="sub" style="margin:4px 0 0">
                        @foreach ($adjuntos->skip(1) as $adjunto)
                            <a href="{{ route('inscripcion.portal.adjunto', ['token' => $token, 'attachment' => $adjunto->id, 'ver' => 1]) }}" target="_blank" rel="noopener">{{ $adjunto->original_name }}</a>@if (! $loop->last), @endif
                        @endforeach
                    </p>
                @endif
            </div>
            <a class="btn btn-primario" target="_blank" rel="noopener"
               href="{{ route('inscripcion.portal.adjunto', ['token' => $token, 'attachment' => $adjuntos->first()->id, 'ver' => 1]) }}">Ver programa</a>
        </div>
    @endif

    @php
        // Una sola tarjeta con todo el programa, separado por día por dentro.
        // Las jornadas sin fecha van al final, juntas.
        $porDia = $jornadas->groupBy(fn ($j) => $j->starts_at?->toDateString() ?? 'sin-fecha');
    @endphp

    <div class="tarjeta">
        <div class="titulo-bloque">
            <h2>Programa de la jornada</h2>
        </div>

        @forelse ($porDia as $dia => $delDia)
            <p class="etiqueta-seccion" @if (! $loop->first) style="margin-top:22px;padding-top:18px;border-top:1px solid #EDF0F3" @endif>
                {{ $dia === 'sin-fecha' ? 'Fecha por confirmar' : $delDia->first()->starts_at->translatedFormat('l d \\d\\e F') }}
            </p>
            <ul class="linea" style="margin-top:12px">
                @foreach ($delDia as $jornada)
                    <li>
                        <span class="hora chip">{{ $jornada->starts_at?->format('H:i') ?? '-' }}</span>
                        <span class="punto" aria-hidden="true"></span>
                        <div>
                            <div class="nombre">{{ $jornada->name }}</div>
                            <div class="detalle">
                                {{ collect([
                                    $jornada->ends_at ? 'Hasta las '.$jornada->ends_at->format('H:i') : null,
                                    $jornada->location,
                                ])->filter()->implode(' · ') }}
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>
        @empty
            <div class="vacio">El programa se publicará pronto.</div>
        @endforelse
    </div>


    @if ($event->location || $event->city || $hayCalendario)
        <div class="tarjeta estado-orden">
            <div>
                <h2>Información del evento</h2>
                @if ($event->location || $event->city)
                    <p class="sub" style="margin:4px 0 0">{{ collect([$event->location, $event->city])->filter()->implode(', ') }}</p>
                @endif
            </div>
            @if ($hayCalendario)
                <a class="btn btn-secundario" href="{{ route('inscripcion.portal.calendario', ['token' => $token]) }}">Agregar al calendario</a>
            @endif
        </div>
    @endif
@endsection
