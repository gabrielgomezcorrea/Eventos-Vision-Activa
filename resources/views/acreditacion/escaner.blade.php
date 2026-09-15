@extends('acreditacion.layout')
@section('titulo', 'Acreditación')

@section('contenido')

    @if ($eventos->count() > 1)
        <form method="GET" action="{{ route('acreditacion.buscar') }}" id="form-evento">
            <label for="event_id">Evento</label>
            <select name="event_id" id="event_id" class="selector-evento"
                    onchange="document.getElementById('form-evento').submit()">
                @foreach ($eventos as $e)
                    <option value="{{ $e->id }}" @selected($evento && $e->id === $evento->id)>{{ $e->name }}</option>
                @endforeach
            </select>
        </form>
    @elseif ($evento)
        <p class="sub" style="margin:-6px 0 14px;color:var(--texto-suave);font-size:.9rem">{{ $evento->name }}</p>
    @endif

    @include('acreditacion._resultado')

    @if (! $resultado || ! $resultado->permiteAcreditar())
        <div class="tarjeta">
            <h2>Escanear credencial</h2>
            <p class="sub">Apunta la cámara al código QR del participante.</p>

            <div id="lector"></div>
            <p class="lector-aviso" id="lector-aviso">Iniciando cámara…</p>

            <form method="POST" action="{{ route('acreditacion.resolver') }}" id="form-qr">
                @csrf
                <input type="hidden" name="event_id" value="{{ $evento?->id }}">
                <input type="hidden" name="credencial" id="credencial-qr">
                <input type="hidden" name="metodo" value="qr">
            </form>
        </div>

        <div class="tarjeta">
            <h2>Ingresar el código a mano</h2>
            <p class="sub">Si el QR no se deja leer, escribe el código de respaldo de la credencial.</p>

            <form method="POST" action="{{ route('acreditacion.resolver') }}">
                @csrf
                <input type="hidden" name="event_id" value="{{ $evento?->id }}">
                <input type="hidden" name="metodo" value="manual">
                <input type="text" name="credencial" class="codigo-entrada" placeholder="ABCD-1234"
                       autocomplete="off" autocapitalize="characters" spellcheck="false">
                <button type="submit" class="btn btn-primario" style="margin-top:12px">Buscar credencial</button>
            </form>
        </div>

        @include('acreditacion._busqueda')
    @endif

@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
    (function () {
        const contenedor = document.getElementById('lector');
        const aviso = document.getElementById('lector-aviso');
        const campo = document.getElementById('credencial-qr');
        const formulario = document.getElementById('form-qr');

        if (! contenedor || typeof Html5Qrcode === 'undefined') {
            if (aviso) aviso.textContent = 'La cámara no está disponible. Usa el código de respaldo.';
            return;
        }

        const lector = new Html5Qrcode('lector');
        let enviado = false;

        function alLeer(texto) {
            if (enviado) return;
            enviado = true;

            // Vibra al leer: en un lugar ruidoso es la única señal confiable.
            if (navigator.vibrate) navigator.vibrate(120);

            campo.value = texto;
            lector.stop().catch(function () {}).finally(function () { formulario.submit(); });
        }

        lector.start(
            { facingMode: 'environment' },
            { fps: 10, qrbox: { width: 240, height: 240 } },
            alLeer,
            function () {}
        ).then(function () {
            aviso.textContent = 'Apunta al código QR. Se lee solo.';
        }).catch(function () {
            aviso.textContent = 'No pudimos abrir la cámara. Revisa los permisos o usa el código de respaldo.';
        });
    })();
</script>
@endpush
