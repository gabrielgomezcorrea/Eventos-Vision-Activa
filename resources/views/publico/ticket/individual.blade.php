<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <meta name="referrer" content="no-referrer">
    <title>Credencial · {{ $ticket->event->name }}</title>
    @include('publico.ticket._estilos')
</head>
<body>
<div class="envoltura">
    <div class="encabezado">
        <h1>Tu credencial de acceso</h1>
        <p>Preséntala impresa o desde tu teléfono en el ingreso al evento.</p>
    </div>

    <div class="acciones">
        <button type="button" class="btn" onclick="window.print()">Imprimir credencial</button>
    </div>

    @include('publico.ticket._credencial', ['ticket' => $ticket])
</div>
</body>
</html>
