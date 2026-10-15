@extends('publico.portal.base')

@section('pestana')
    @php
        $disponibles = $orden->payment_status->liberaCredenciales() && $tickets->isNotEmpty();
        // Sin participantes todavía se muestra igual la credencial, con un
        // nombre de ejemplo: así se ve desde el primer momento qué va a recibir.
        $tarjetas = $participantes->isEmpty() ? collect([null]) : $participantes;
        $accesoDeEjemplo = $event->accessTypes->where('is_active', true)->first()?->name;
        $estadoDeLaCredencial = $orden->esBorrador() ? 'Incompleta' : $orden->payment_status->label();
    @endphp

    @foreach ($tarjetas as $participante)
        @php $ticket = ($disponibles && $participante) ? $tickets->get($participante->id) : null; @endphp
        <div class="tarjeta credencial-digital">
            @if ($ticket)
                <div class="qr-imagen" style="width:160px;height:160px;flex-shrink:0">{!! $qr->svg($ticket->url(), 160) !!}</div>
            @else
                <span class="id-credencial" aria-hidden="true">ID</span>
            @endif
            <div class="credencial-digital-datos">
                <p class="etiqueta-seccion">Credencial digital</p>
                <h2>{{ $participante?->nombre_completo ?? 'Nombre Apellido' }}</h2>
                <p class="credencial-digital-evento">
                    {{ collect([$event->name, $participante?->accessType?->name ?? $accesoDeEjemplo])->filter()->implode(' · ') }}
                </p>
                <div class="credencial-digital-pie">
                    @if ($orden->number)<span>{{ $orden->number }}</span>@endif
                    @if ($ticket)
                        <span class="chip chip-exito">Lista</span>
                    @elseif ($orden->esBorrador())
                        <span>-</span>
                    @else
                        <span class="chip chip-aviso">{{ $estadoDeLaCredencial }}</span>
                    @endif
                </div>
                @if ($ticket)
                    <p class="etiqueta-seccion" style="margin-top:12px">Código de respaldo</p>
                    <p style="margin:0;font:700 1.3rem ui-monospace,Menlo,monospace;letter-spacing:.08em">{{ $ticket->code }}</p>
                @endif
            </div>
            @if ($ticket)
                <button type="button" class="btn btn-secundario" data-descargar-qr
                        data-nombre="{{ \Illuminate\Support\Str::slug($participante->nombre_completo) ?: $ticket->code }}">Descargar QR</button>
            @endif
        </div>
    @endforeach

    @unless ($disponibles)
        <div class="tarjeta estado-orden">
            <div>
                <h2>Tu credencial aún no está disponible</h2>
                <p class="sub" style="margin:4px 0 0">Podrás descargarla cuando confirmemos el pago de tu inscripción.</p>
            </div>
            <button type="button" class="btn btn-apagado" disabled>Descargar credencial</button>
        </div>
    @endunless

    @if ($disponibles)
        <div class="acciones" style="margin-top:0">
            <button type="button" class="btn btn-secundario" onclick="window.print()">Imprimir todas</button>
        </div>

        {{-- Convierte el QR que ya está en la página a PNG y lo descarga. Sin
             servidor de por medio: el SVG está dibujado, solo pasa por un canvas. --}}
        <script>
        document.querySelectorAll('[data-descargar-qr]').forEach(function (boton) {
            boton.addEventListener('click', function () {
                var svg = boton.closest('.tarjeta').querySelector('.qr-imagen svg');
                if (! svg) { return; }
                var lado = 600;
                var lienzo = document.createElement('canvas');
                lienzo.width = lado;
                lienzo.height = lado;
                var imagen = new Image();
                imagen.onload = function () {
                    var pincel = lienzo.getContext('2d');
                    pincel.fillStyle = '#ffffff';
                    pincel.fillRect(0, 0, lado, lado);
                    pincel.drawImage(imagen, 0, 0, lado, lado);
                    var enlace = document.createElement('a');
                    enlace.href = lienzo.toDataURL('image/png');
                    enlace.download = 'credencial-' + boton.dataset.nombre + '.png';
                    enlace.click();
                };
                imagen.src = 'data:image/svg+xml;base64,' + window.btoa(
                    unescape(encodeURIComponent(new XMLSerializer().serializeToString(svg)))
                );
            });
        });
        </script>
    @endif
@endsection
