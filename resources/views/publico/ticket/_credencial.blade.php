@php
    $participante = $ticket->participant;
    $acceso = $participante->accessType;
    $evento = $ticket->event;
    $jornadas = $acceso?->sessions ?? collect();
@endphp

<article class="credencial">
    <header class="credencial-cabecera">
        <p class="credencial-evento">{{ $evento->name }}</p>
        @if ($evento->location)
            <p class="credencial-lugar">{{ $evento->location }}@if ($evento->city), {{ $evento->city }}@endif</p>
        @endif
    </header>

    <div class="credencial-cuerpo">
        <div class="credencial-datos">
            <p class="etiqueta">Participante</p>
            <p class="nombre">{{ $participante->nombre_completo }}</p>

            @if ($participante->establishment?->name)
                <p class="institucion">{{ $participante->establishment->name }}</p>
            @endif

            <p class="etiqueta" style="margin-top:14px">Acceso</p>
            <p class="acceso">{{ $acceso?->name }}</p>

            @if ($jornadas->isNotEmpty())
                <ul class="jornadas">
                    @foreach ($jornadas as $jornada)
                        <li>
                            {{ $jornada->name }}
                            @if ($jornada->starts_at)
                                — {{ $jornada->starts_at->translatedFormat('d \d\e F, H:i') }}
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($evento->usaPulseras() && $acceso?->wristband_label)
                <p class="pulsera">
                    <span class="pulsera-punto" style="background: {{ $acceso->wristband_color ?: '#084887' }}"></span>
                    Pulsera: {{ $acceso->wristband_label }}
                </p>
            @endif
        </div>

        <div class="credencial-qr">
            <div class="qr-imagen">{!! $qr->svg($ticket->url(), 240) !!}</div>
            {{-- Descarga del QR como PNG, dibujado en el navegador: sirve para
                 reenviarlo por WhatsApp o pegarlo en un documento, y no obliga
                 a instalar una extensión de imágenes en el servidor. --}}
            <button type="button" class="btn btn-qr" data-descargar-qr
                    data-nombre="{{ \Illuminate\Support\Str::slug($participante->nombre_completo) ?: $ticket->code }}">
                Descargar QR
            </button>
            <p class="etiqueta" style="margin-top:8px">Código de respaldo</p>
            <p class="codigo">{{ $ticket->code }}</p>
        </div>
    </div>

    <footer class="credencial-pie">
        Presenta este código en el ingreso. La acreditación se realiza una sola vez.
        Inscripción {{ $ticket->order->number }}.
    </footer>
</article>
