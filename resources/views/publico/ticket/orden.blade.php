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
</body>
</html>
