<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <meta name="referrer" content="no-referrer">
    <title>Credenciales · {{ $orden->number }}</title>
    @include('publico.ticket._estilos')
</head>
<body>
<div class="envoltura">
    <div class="encabezado">
        <h1>Credenciales de la inscripción {{ $orden->number }}</h1>
        <p>
            {{ $tickets->count() }} {{ $tickets->count() === 1 ? 'credencial' : 'credenciales' }}
            para {{ $orden->event->name }}. Imprímelas o reenvíalas a cada participante.
        </p>
    </div>

    @if ($tickets->isEmpty())
        <div class="aviso">
            Todavía no hay credenciales emitidas para esta inscripción.
            Se liberan cuando el pago queda aprobado.
        </div>
    @else
        <div class="acciones">
            <button type="button" class="btn" onclick="window.print()">Imprimir todas</button>
        </div>

        @foreach ($tickets as $ticket)
            @include('publico.ticket._credencial', ['ticket' => $ticket])
        @endforeach
    @endif
</div>

{{--
    Convierte el QR que ya está en la página a PNG y lo descarga. Sin servidor
    de por medio: el SVG está dibujado, solo se pasa por un canvas.
--}}
<script>
document.querySelectorAll('[data-descargar-qr]').forEach(function (boton) {
    boton.addEventListener('click', function () {
        var svg = boton.parentNode.querySelector('.qr-imagen svg');

        if (! svg) {
            return;
        }

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
</body>
</html>
