@php
    $titulo = $titulo ?? 'Este enlace ya no es válido';
    $explicacion = $explicacion ?? 'Puede haber expirado o haber sido reemplazado por uno más reciente.';
    $accionUrl = $accionUrl ?? null;
    $accionTexto = $accionTexto ?? null;
    $evento = $evento ?? null;
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <meta name="referrer" content="no-referrer">
    <title>{{ $titulo }}</title>
    <style>
        body{margin:0;background:#F7F5FB;color:#1F2937;
             font:16px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;
             display:grid;place-items:center;min-height:100vh;padding:20px}
        .tarjeta{background:#fff;border:1px solid #D0D5DD;border-radius:12px;
                 padding:32px;max-width:480px;text-align:center}
        h1{font-size:1.25rem;margin:0 0 12px;line-height:1.3}
        p{color:#667085;margin:0 0 20px;font-size:.95rem}
        .boton{display:inline-block;background:#084887;color:#fff;text-decoration:none;
               font-weight:600;padding:13px 22px;border-radius:8px;font-size:1rem}
        .boton:hover{background:#063a6d}
        .boton:focus-visible{outline:3px solid #F58A07;outline-offset:2px}
    </style>
</head>
<body>
    <div class="tarjeta">
        <h1>{{ $titulo }}</h1>
        <p>{{ $explicacion }}</p>
        @if ($accionUrl)
            <a class="boton" href="{{ $accionUrl }}">{{ $accionTexto }}</a>
        @endif
        @if ($evento && ($evento->contact_email || $evento->contact_phone))
            <p style="margin:20px 0 0">¿Necesitas ayuda? Contáctanos:</p>
            @include('publico._contacto', ['evento' => $evento])
        @endif
    </div>
</body>
</html>
