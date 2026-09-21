@if ($resultado)
    @php
        $ticket = $resultado->ticket;
        $participante = $ticket?->participant;
        $acceso = $participante?->accessType;
        $usaPulsera = $ticket?->event?->usaPulseras() && $acceso?->wristband_label;
    @endphp

    <div class="resultado {{ $resultado->tipo->color() }}">
        <p class="resultado-titulo">
            <span class="simbolo" aria-hidden="true">
                @switch($resultado->tipo->color())
                    @case('exito') ✓ @break
                    @case('aviso') ! @break
                    @default ✕
                @endswitch
            </span>
            {{ ($recienAcreditado ?? false) ? 'Acreditado' : $resultado->tipo->titulo() }}
        </p>

        <p class="resultado-mensaje">
            @if ($recienAcreditado ?? false)
                Pulsera entregada. Puedes escanear al siguiente participante.
            @else
                {{ $resultado->tipo->mensaje() }}
            @endif
        </p>

        @if ($resultado->tipo === \App\Enums\ResultadoAcreditacion::OtroEvento && $ticket)
            <p style="margin:0 0 14px;font-size:1rem">
                Corresponde a <strong>{{ $ticket->event->name }}</strong>.
            </p>
        @endif

        @if ($participante)
            <div class="persona">
                <p class="nombre">{{ $participante->nombre_completo }}</p>

                @if ($participante->establishment?->name)
                    <p class="institucion">{{ $participante->establishment->name }}</p>
                @endif

                <p class="acceso">
                    {{ $acceso?->name }}
                    @if ($ticket->order->kind === \App\Enums\OrderKind::Invitado)
                        · Invitado especial
                    @endif
                </p>
                <p class="orden">Inscripción {{ $ticket->order->number }} · Código {{ $ticket->code }}</p>
            </div>

            @if ($usaPulsera)
                <div class="pulsera">
                    <span class="pulsera-muestra" style="background: {{ $acceso->wristband_color ?: '#084887' }}"></span>
                    <div class="pulsera-texto">
                        <p class="etiqueta">Pulsera a entregar</p>
                        <p class="valor">{{ $acceso->wristband_label }}</p>
                    </div>
                </div>
            @endif

            @if ($resultado->acreditacion)
                <p style="margin:14px 0 0;font-size:.9rem">
                    Acreditado el
                    <strong>{{ $resultado->acreditacion->accredited_at->translatedFormat('d \d\e F, H:i') }}</strong>
                    @if ($resultado->acreditacion->user_label)
                        por {{ $resultado->acreditacion->user_label }}
                    @endif.
                    @if ($resultado->acreditacion->wristband_label)
                        Ya recibió la pulsera <strong>{{ $resultado->acreditacion->wristband_label }}</strong>.
                    @endif
                </p>
            @endif
        @endif

        @if ($resultado->permiteAcreditar() && ! ($recienAcreditado ?? false))
            <form method="POST" action="{{ route('acreditacion.confirmar') }}" style="margin-top:16px">
                @csrf
                <input type="hidden" name="ticket_id" value="{{ $ticket->id }}">
                <input type="hidden" name="event_id" value="{{ $evento?->id }}">
                <input type="hidden" name="metodo" value="{{ request('metodo', 'qr') }}">
                <button type="submit" class="btn btn-confirmar">
                    Confirmar acreditación y entregar pulsera
                </button>
            </form>
        @endif

        <a href="{{ route('acreditacion.inicio', ['event_id' => $evento?->id]) }}"
           class="btn btn-secundario" style="margin-top:10px">
            Escanear otro participante
        </a>
    </div>
@endif
