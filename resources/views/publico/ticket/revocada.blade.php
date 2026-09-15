<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Credencial anulada</title>
    <style>
        body{margin:0;background:#F7F5FB;color:#1F2937;
             font:16px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;
             display:grid;place-items:center;min-height:100vh;padding:20px}
        .tarjeta{background:#fff;border:1px solid #D0D5DD;border-radius:12px;
                 padding:32px;max-width:460px;text-align:center}
        h1{font-size:1.2rem;margin:0 0 10px}
        p{color:#667085;margin:0 0 8px;font-size:.94rem}
    </style>
</head>
<body>
    <div class="tarjeta">
        <h1>Esta credencial ya no es válida</h1>
        <p>Fue anulada y reemplazada por otra.</p>
        <p>El responsable de la inscripción tiene la credencial vigente. Si necesitas ayuda, contáctanos.</p>
        @if ($ticket->event->contact_email)
            <p><a href="mailto:{{ $ticket->event->contact_email }}">{{ $ticket->event->contact_email }}</a></p>
        @endif
    </div>
</body>
</html>
